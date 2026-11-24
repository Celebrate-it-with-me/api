<?php

namespace App\Http\Services\AppServices\Invitation;

use App\Http\Services\AppServices\SuggestedMusicServices;
use App\Http\Services\Logger\EventActivityLogger;
use App\Http\Services\Spotify\SpotifyClient;
use App\Models\Events;
use App\Models\Guest;
use App\Models\SuggestedMusic;
use App\Models\SuggestedMusicConfig;
use App\Models\SuggestedMusicVote;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Suggested music of the public invitation (docs/plans/invitation-premium-modules-contract.md, section 5). The section
 * only carries configuration; the list and the search are read lazily by the page, and suggestions and votes are
 * written by the guest of the link. Caps (decided by the owner, kept from before): `music.max_suggestions_per_guest`
 * suggestions per guest, one vote per song per guest, and a vote budget of 1 + companions per link.
 */
class InvitationSongs
{
    public const PER_PAGE = 20;

    public const SEARCH_TTL_MINUTES = 10;

    public const SEARCH_DEFAULT_LIMIT = 5;

    public const SEARCH_MAX_LIMIT = 10;

    public function __construct(private readonly SpotifyClient $spotify) {}

    /**
     * Configuration of the `suggested_music` section. `mySuggestions` is per guest: the shared payload carries 0 and
     * `forGuest` replaces it.
     *
     * @return array<string, mixed>
     */
    public function sectionData(Events $event): array
    {
        $config = $this->config($event);
        $text = fn (?string $value) => is_string($value) && trim($value) !== '' ? trim($value) : null;

        return [
            'heading' => $text($config?->title),
            'subHeading' => $text($config?->sub_title),
            'usePreview' => (bool) $config?->use_preview,
            'useVotes' => (bool) $config?->use_vote_system,
            'maxSuggestions' => $this->maxSuggestions(),
            'mySuggestions' => 0,
            'canWrite' => $this->canWrite($event),
        ];
    }

    public function mySuggestions(Events $event, Guest $guest): int
    {
        return SuggestedMusicServices::countByGuest($guest, $event->id);
    }

    /** An invitation kept after its owner deleted her account, and the Save the date, are read only. */
    public function canWrite(Events $event): bool
    {
        return ! $event->isAccountArchived() && $event->invitation_stage !== Events::STAGE_SAVE_THE_DATE;
    }

    public function maxSuggestions(): int
    {
        return (int) config('music.max_suggestions_per_guest', 5);
    }

    /** @return array{page: LengthAwarePaginator, usePreview: bool, votesTotal: int, votesRemaining: int} */
    public function page(Events $event, Guest $guest, string $orderBy): array
    {
        $config = $this->config($event);

        $query = SuggestedMusic::query()
            ->where('event_id', $event->id)
            ->withCount(['musicVotes as up_votes' => fn ($q) => $q->where('vote_type', 'up')])
            ->withExists(['musicVotes as voted_by_me' => fn ($q) => $q->where('guest_id', $guest->id)]);

        $orderBy === 'recent'
            ? $query->orderByDesc('created_at')->orderByDesc('id')
            : $query->orderByDesc('up_votes')->orderByDesc('id');

        return [
            'page' => $query->paginate(self::PER_PAGE),
            'usePreview' => (bool) $config?->use_preview,
        ] + $this->budget($guest);
    }

    /** @return array{votesTotal: int, votesRemaining: int} */
    public function budget(Guest $guest): array
    {
        $total = 1 + $guest->companions()->count();
        $used = SuggestedMusicVote::where('guest_id', $guest->id)->count();

        return ['votesTotal' => $total, 'votesRemaining' => max(0, $total - $used)];
    }

    /**
     * Catalogue search, cached for ten minutes by normalized query (only successes are cached). SpotifyUnavailable
     * passes through to the controller.
     *
     * @return array<string, mixed>
     */
    public function search(Events $event, string $query, ?int $limit): array
    {
        $limit ??= min(self::SEARCH_MAX_LIMIT, max(1, (int) ($this->config($event)?->search_limit ?: self::SEARCH_DEFAULT_LIMIT)));
        $key = 'invitation-songs-search:' . sha1(mb_strtolower($query) . '|' . $limit);

        return Cache::remember($key, now()->addMinutes(self::SEARCH_TTL_MINUTES), fn () => $this->spotify->searchTracks($query, $limit));
    }

    /**
     * @param  array{platformId: string, title: string, artist: string, album?: ?string, thumbnailUrl?: ?string, previewUrl?: ?string}  $song
     */
    public function suggest(Events $event, Guest $guest, array $song): SuggestedMusic
    {
        try {
            $music = DB::transaction(function () use ($event, $guest, $song) {
                // Serializes the suggestions of one guest, so two quick taps cannot pass the cap together.
                Guest::query()->whereKey($guest->id)->lockForUpdate()->first();

                if (SuggestedMusicServices::countByGuest($guest, $event->id) >= $this->maxSuggestions()) {
                    throw new InvitationSongException('limit', 'limit');
                }
                if (SuggestedMusic::where('event_id', $event->id)->where('platformId', $song['platformId'])->exists()) {
                    throw new InvitationSongException('duplicate', 'duplicate');
                }

                return SuggestedMusic::create([
                    'event_id' => $event->id,
                    'title' => $song['title'],
                    'artist' => $song['artist'],
                    'album' => $song['album'] ?? '',
                    'platformId' => $song['platformId'],
                    'platform' => 'spotify',
                    'thumbnailUrl' => $song['thumbnailUrl'] ?? '',
                    'previewUrl' => $song['previewUrl'] ?? null,
                    'suggested_by_entity' => $guest->getMorphClass(),
                    'suggested_by_id' => $guest->id,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw new InvitationSongException('duplicate', 'duplicate');
        }

        EventActivityLogger::logOnce($event->id, 'music_suggested', $guest, $music, ['name' => $guest->name, 'song' => $music->title], 10);

        return $music;
    }

    /** Only the guest's own suggestion; anyone else's (or the organizer's, or another event's) is a 404. */
    public function remove(Events $event, Guest $guest, int $id): void
    {
        $song = $this->own($event, $guest, $id);
        abort_if($song === null, 404, 'Song not found.');

        $song->delete(); // its votes go with it (foreign key)
    }

    /**
     * Votes `true` (one per song per guest; a legacy downvote becomes an upvote) or `false` (removes it).
     *
     * @return array{song: SuggestedMusic, voted: bool}
     */
    public function vote(Events $event, Guest $guest, int $id, bool $vote): array
    {
        $song = SuggestedMusic::query()->where('event_id', $event->id)->find($id);
        abort_if($song === null, 404, 'Song not found.');

        if (! $this->config($event)?->use_vote_system) {
            throw new InvitationSongException('votes_disabled', 'votes', 403);
        }

        DB::transaction(function () use ($guest, $song, $vote) {
            Guest::query()->whereKey($guest->id)->lockForUpdate()->first();
            $existing = SuggestedMusicVote::where('suggested_music_id', $song->id)->where('guest_id', $guest->id)->first();

            if (! $vote) {
                $existing?->delete();

                return;
            }
            if ($existing !== null) {
                $existing->vote_type !== 'up' && $existing->update(['vote_type' => 'up']);

                return;
            }
            if ($this->budget($guest)['votesRemaining'] <= 0) {
                throw new InvitationSongException('votes_exhausted', 'votes');
            }

            SuggestedMusicVote::create(['suggested_music_id' => $song->id, 'guest_id' => $guest->id, 'vote_type' => 'up']);
        });

        return ['song' => $song, 'voted' => $vote];
    }

    /** One song as the page draws it: no ids or names of other guests. @return array<string, mixed> */
    public function present(Events $event, Guest $guest, SuggestedMusic $song, ?bool $usePreview = null): array
    {
        $usePreview ??= (bool) $this->config($event)?->use_preview;

        $loaded = $song->getAttributes();
        $votes = array_key_exists('up_votes', $loaded) ? $loaded['up_votes'] : $song->musicVotes()->where('vote_type', 'up')->count();
        $votedByMe = array_key_exists('voted_by_me', $loaded) ? $loaded['voted_by_me'] : $song->musicVotes()->where('guest_id', $guest->id)->exists();

        return [
            'id' => $song->id,
            'title' => $song->title,
            'artist' => $song->artist,
            'platform' => $song->platform,
            'platformId' => $song->platformId,
            'thumbnailUrl' => $song->thumbnailUrl !== '' ? $song->thumbnailUrl : null,
            'previewUrl' => $usePreview && $song->previewUrl ? $song->previewUrl : null,
            'votes' => (int) $votes,
            'votedByMe' => (bool) $votedByMe,
            'suggestedByMe' => $this->isOwn($song, $guest),
        ];
    }

    private function own(Events $event, Guest $guest, int $id): ?SuggestedMusic
    {
        $song = SuggestedMusic::query()->where('event_id', $event->id)->find($id);

        return $song !== null && $this->isOwn($song, $guest) ? $song : null;
    }

    private function isOwn(SuggestedMusic $song, Guest $guest): bool
    {
        return in_array($song->suggested_by_entity, [$guest->getMorphClass(), Guest::class], true)
            && (int) $song->suggested_by_id === (int) $guest->id;
    }

    private function config(Events $event): ?SuggestedMusicConfig
    {
        return SuggestedMusicConfig::query()->where('event_id', $event->id)->first();
    }
}
