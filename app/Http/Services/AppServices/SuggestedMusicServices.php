<?php

namespace App\Http\Services\AppServices;

use App\Models\Events;
use App\Models\Guest;
use App\Models\MainGuest;
use App\Models\SaveTheDate;
use App\Models\SuggestedMusic;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SuggestedMusicServices
{
    protected Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /**
     * Get event save the date.
     * @param Events $event
     * @return mixed
     */
    public function getSuggestedMusic(Events $event, array $options = []): LengthAwarePaginator
    {
        $perPage = $options['perPage'] ?? 10;
        $pageSelected = $this->request->input('pageSelected', 1);
        $orderBy = $options['orderBy'] ?? 'recent'; // 'recent' or 'popular'
        $search = $this->request->input('search');

        $query = SuggestedMusic::query()
            ->where('event_id', $event->id)
            ->with(['suggestedBy', 'musicVotes']);

        // Apply search
        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('title', 'LIKE', "%{$search}%")
                    ->orWhere('artist', 'LIKE', "%{$search}%")
                    ->orWhere('album', 'LIKE', "%{$search}%");
            });
        }

        // Apply ordering
        if ($orderBy === 'popular') {
            $query->popular();
        } else {
            $query->recent();
        }

        return $query->paginate($perPage, ['*'], 'page', $pageSelected);
    }

    /**
     * Create suggested music by ORGANIZER
     */
    public function createByOrganizer(Events $event, User $user): Model|SuggestedMusic
    {
        // Check for duplicate
        $this->checkDuplicateSong($event->id, $this->request->get('platformId'));

        return DB::transaction(function() use ($event, $user) {
            return SuggestedMusic::create([
                'event_id' => $event->id,
                'title' => $this->request->get('title'),
                'artist' => $this->request->get('artist'),
                'album' => $this->request->get('album'),
                'platformId' => $this->request->get('platformId'),
                'platform' => 'spotify',
                'thumbnailUrl' => $this->request->get('thumbnailUrl'),
                'previewUrl' => $this->request->get('previewUrl'),
                'suggested_by_entity' => 'user',
                'suggested_by_id' => $user->id,
            ]);
        });
    }

    /**
     * Create suggested music by GUEST (with access code)
     */
    public function createByGuest(Events $event, string $accessCode): Model|SuggestedMusic
    {
        // Validate access code
        $guest = Guest::where('code', $accessCode)
            ->where('event_id', $event->id)
            ->first();

        if (!$guest) {
            throw ValidationException::withMessages([
                'accessCode' => 'Invalid access code for this event.'
            ]);
        }

        // Check suggestion limit
        $this->checkSuggestionLimit($guest, $event->id);

        // Check for duplicate
        $this->checkDuplicateSong($event->id, $this->request->get('platformId'));

        return DB::transaction(function() use ($event, $guest) {
            return SuggestedMusic::create([
                'event_id' => $event->id,
                'title' => $this->request->get('title'),
                'artist' => $this->request->get('artist'),
                'album' => $this->request->get('album'),
                'platformId' => $this->request->get('platformId'),
                'platform' => 'spotify',
                'thumbnailUrl' => $this->request->get('thumbnailUrl'),
                'previewUrl' => $this->request->get('previewUrl'),
                'suggested_by_entity' => 'guest',
                'suggested_by_id' => $guest->id,
            ]);
        });
    }

    /**
     * Remove suggested music
     * @throws \Throwable
     */
    public function remove(SuggestedMusic $suggestedMusic): SuggestedMusic
    {
        $clone = clone $suggestedMusic;

        DB::transaction(function() use ($suggestedMusic) {
            // Votes will be cascade deleted by foreign key
            $suggestedMusic->delete();
        });

        return $clone;
    }

    /**
     * Check if guest has reached suggestion limit
     */
    protected function checkSuggestionLimit(Guest $guest, int $eventId): void
    {
        $maxSuggestions = config('music.max_suggestions_per_guest', 5);

        $currentCount = SuggestedMusic::where('event_id', $eventId)
            ->where('suggested_by_entity', Guest::class)
            ->where('suggested_by_id', $guest->id)
            ->count();

        if ($currentCount >= $maxSuggestions) {
            throw ValidationException::withMessages([
                'limit' => "You have reached the maximum of {$maxSuggestions} song suggestions."
            ]);
        }
    }

    /**
     * Check if song already exists for event
     */
    protected function checkDuplicateSong(int $eventId, string $platformId): void
    {
        $existing = SuggestedMusic::where('event_id', $eventId)
            ->where('platformId', $platformId)
            ->first();

        if ($existing) {
            throw ValidationException::withMessages([
                'duplicate' => 'This song has already been suggested.',
                'existingMusicId' => $existing->id,
            ])->status(409); // Conflict
        }
    }
}
