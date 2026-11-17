<?php

use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\Guest;
use App\Models\SuggestedMusic;
use App\Models\SuggestedMusicConfig;
use App\Models\SuggestedMusicVote;
use App\Providers\RouteServiceProvider;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    RateLimiter::clear('x');
    Cache::flush();
    config(['services.spotify.client_id' => 'test-id', 'services.spotify.client_secret' => 'test-secret']);
});

const IS_URL = '/api/v1/app/invitation/xv-musica/SNG123';

/** A published event with a main guest (code SNG123) and a companion, on the given plan, with the module configured. */
function songsEvent(string $plan = 'premium', array $event = [], array $config = ['use_preview' => true, 'use_vote_system' => true]): array
{
    $event = Events::factory()->create(array_merge([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', $plan)->value('id'),
        'status' => 'published',
        'custom_url_slug' => 'xv-musica',
    ], $event));
    $main = Guest::factory()->create(['event_id' => $event->id, 'parent_id' => null, 'name' => 'Rosa Fernández', 'code' => 'SNG123', 'rsvp_status' => 'pending']);
    $companion = Guest::factory()->create(['event_id' => $event->id, 'parent_id' => $main->id, 'rsvp_status' => 'pending']);

    if ($config !== []) {
        SuggestedMusicConfig::create(array_merge(['event_id' => $event->id, 'title' => null, 'sub_title' => null, 'main_color' => '#000', 'secondary_color' => '#fff', 'search_limit' => 10], $config));
    }

    return [$event, $main, $companion];
}

function spotifyId(int $n): string
{
    return str_pad((string) $n, 22, 'x', STR_PAD_LEFT);
}

function addSong(Events $event, ?Guest $by, int $n, array $attributes = []): SuggestedMusic
{
    return SuggestedMusic::create(array_merge([
        'event_id' => $event->id, 'title' => "Canción {$n}", 'artist' => "Artista {$n}", 'album' => 'Álbum', 'platform' => 'spotify',
        'platformId' => spotifyId($n), 'thumbnailUrl' => "https://i.scdn.co/image/{$n}", 'previewUrl' => "https://p.scdn.co/mp3-preview/{$n}",
        'suggested_by_entity' => $by ? 'guest' : 'user', 'suggested_by_id' => $by?->id ?? 999,
    ], $attributes));
}

function songBody(int $n, array $extra = []): array
{
    return array_merge(['platformId' => spotifyId($n), 'title' => "Canción {$n}", 'artist' => "Artista {$n}", 'album' => 'Álbum', 'thumbnailUrl' => "https://i.scdn.co/image/{$n}"], $extra);
}

function fakeSpotifyCatalogue(): void
{
    Http::fake([
        'accounts.spotify.com/*' => Http::response(['access_token' => 'tok-secret', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        'api.spotify.com/*' => Http::response(['tracks' => ['items' => [[
            'id' => 't1', 'name' => 'Song', 'uri' => 'spotify:track:t1', 'duration_ms' => 1000, 'preview_url' => null, 'popularity' => 80,
            'external_urls' => ['spotify' => 'https://open.spotify.com/track/t1'],
            'artists' => [['id' => 'a1', 'name' => 'Artist']],
            'album' => ['name' => 'Album', 'images' => [['url' => 'https://img/1', 'height' => 640]]],
        ]]]]),
    ]);
}

describe('the suggested music section in the invitation', function () {
    it('carries only configuration, never the list', function () {
        [$event, $main] = songsEvent();
        addSong($event, $main, 1);

        $section = collect($this->getJson(IS_URL)->assertOk()->json('data.sections'))->firstWhere('type', 'suggested_music');

        expect($section['data'])->toBe(['heading' => null, 'subHeading' => null, 'usePreview' => true, 'useVotes' => true, 'maxSuggestions' => 5, 'mySuggestions' => 1, 'canWrite' => true])
            ->and(json_encode($section))->not->toContain('Canción 1');
    });

    it('counts mySuggestions per guest and keeps the shared payload free of it', function () {
        [$event, $main] = songsEvent();
        $other = Guest::factory()->create(['event_id' => $event->id, 'parent_id' => null, 'code' => 'OTH999']);
        addSong($event, $main, 1);
        addSong($event, $main, 2);
        addSong($event, $other, 3);

        $mine = fn (string $code) => collect($this->getJson("/api/v1/app/invitation/xv-musica/{$code}")->json('data.sections'))->firstWhere('type', 'suggested_music')['data']['mySuggestions'];

        expect($mine('SNG123'))->toBe(2)->and($mine('OTH999'))->toBe(1)->and($mine('SNG123'))->toBe(2);
    });

    it('takes its texts and switches from the organizer configuration and follows its changes', function () {
        [$event] = songsEvent('premium', [], []);
        $this->getJson(IS_URL)->assertOk(); // fills the shared cache

        SuggestedMusicConfig::create(['event_id' => $event->id, 'title' => 'Tu música', 'sub_title' => 'Para bailar', 'main_color' => '#000', 'secondary_color' => '#fff', 'use_preview' => 0, 'use_vote_system' => 1, 'search_limit' => 10]);

        $data = collect($this->getJson(IS_URL)->json('data.sections'))->firstWhere('type', 'suggested_music')['data'];

        expect($data)->toMatchArray(['heading' => 'Tu música', 'subHeading' => 'Para bailar', 'usePreview' => false, 'useVotes' => true]);
    });

    it('is left out on a plan without the module, and its routes answer module_locked', function () {
        songsEvent('basic');

        $types = collect($this->getJson(IS_URL)->json('data.sections'))->pluck('type')->all();
        expect($types)->not->toContain('suggested_music');

        foreach ([['getJson', ''], ['getJson', '/search?q=ab'], ['postJson', ''], ['deleteJson', '/1'], ['postJson', '/1/vote']] as [$method, $path]) {
            $this->{$method}(IS_URL . '/songs' . $path, [])->assertForbidden()->assertJsonPath('code', 'module_locked');
        }
    });

    it('is read only (canWrite false) once the owner deleted her account', function () {
        songsEvent('premium', ['status' => 'archived', 'purge_after' => now()->addDays(30)]);

        $data = collect($this->getJson(IS_URL)->assertOk()->json('data.sections'))->firstWhere('type', 'suggested_music')['data'];

        expect($data['canWrite'])->toBeFalse();
    });
});

describe('GET invitation songs', function () {
    it('lists with the public shape only and the guest-relative flags', function () {
        [$event, $main, $companion] = songsEvent();
        $other = Guest::factory()->create(['event_id' => $event->id, 'parent_id' => null, 'name' => 'Tía Marta', 'code' => 'OTH999']);
        $mine = addSong($event, $main, 1);
        $theirs = addSong($event, $other, 2);
        SuggestedMusicVote::create(['suggested_music_id' => $theirs->id, 'guest_id' => $main->id, 'vote_type' => 'up']);
        SuggestedMusicVote::create(['suggested_music_id' => $theirs->id, 'guest_id' => $other->id, 'vote_type' => 'up']);

        $response = $this->getJson(IS_URL . '/songs')->assertOk();
        $rows = collect($response->json('data'));

        expect($rows->pluck('id')->all())->toBe([$theirs->id, $mine->id])
            ->and(array_keys($rows[0]))->toBe(['id', 'title', 'artist', 'thumbnailUrl', 'previewUrl', 'votes', 'votedByMe', 'suggestedByMe'])
            ->and($rows[0])->toMatchArray(['votes' => 2, 'votedByMe' => true, 'suggestedByMe' => false])
            ->and($rows[1])->toMatchArray(['votes' => 0, 'votedByMe' => false, 'suggestedByMe' => true])
            ->and($response->json('meta'))->toBe(['currentPage' => 1, 'lastPage' => 1, 'total' => 2, 'votesTotal' => 2, 'votesRemaining' => 1])
            ->and($response->getContent())->not->toContain('OTH999')->not->toContain('Tía Marta')->not->toContain('SNG123')->not->toContain('suggested_by');
    });

    it('orders by recent when asked and rejects an unknown order', function () {
        [$event, $main] = songsEvent();
        $old = addSong($event, $main, 1);
        $old->forceFill(['created_at' => now()->subDay()])->save();
        $voted = addSong($event, $main, 2);
        $voted->forceFill(['created_at' => now()->subHour()])->save();
        $new = addSong($event, $main, 3);
        SuggestedMusicVote::create(['suggested_music_id' => $old->id, 'guest_id' => $main->id, 'vote_type' => 'up']);

        expect(collect($this->getJson(IS_URL . '/songs?orderBy=recent')->json('data'))->pluck('id')->all())->toBe([$new->id, $voted->id, $old->id])
            ->and(collect($this->getJson(IS_URL . '/songs?orderBy=popular')->json('data'))->first()['id'])->toBe($old->id);

        $this->getJson(IS_URL . '/songs?orderBy=hax')->assertStatus(422)->assertJsonPath('errors.orderBy.0', 'invalid');
    });

    it('shows the preview only when the organizer allows it', function () {
        [$event, $main] = songsEvent('premium', [], ['use_preview' => false, 'use_vote_system' => true]);
        addSong($event, $main, 1);

        expect($this->getJson(IS_URL . '/songs')->json('data.0.previewUrl'))->toBeNull();

        SuggestedMusicConfig::where('event_id', $event->id)->update(['use_preview' => true]);
        expect($this->getJson(IS_URL . '/songs')->json('data.0.previewUrl'))->toBe('https://p.scdn.co/mp3-preview/1');
    });

    it('pages by twenty', function () {
        [$event, $main] = songsEvent();
        foreach (range(1, 22) as $i) {
            addSong($event, $main, $i);
        }

        expect($this->getJson(IS_URL . '/songs')->json('data'))->toHaveCount(20)
            ->and($this->getJson(IS_URL . '/songs?page=2')->json('data'))->toHaveCount(2);
    });

    it('does not mix the songs of another event', function () {
        songsEvent();
        $foreign = Events::factory()->create(['status' => 'published', 'custom_url_slug' => 'otro-evento']);
        addSong($foreign, null, 1);

        expect($this->getJson(IS_URL . '/songs')->assertOk()->json('data'))->toBe([]);
    });

    it('answers the same 404 for a wrong code, an unknown slug, a companion code and a draft', function (string $url) {
        [$event, $main, $companion] = songsEvent();
        $companion->forceFill(['code' => 'CMP123'])->save();

        $this->getJson($url . '/songs')->assertNotFound();
        $this->getJson($url . '/songs/search?q=ab')->assertNotFound();
        $this->postJson($url . '/songs', songBody(1))->assertNotFound();
        $this->deleteJson($url . '/songs/1')->assertNotFound();
        $this->postJson($url . '/songs/1/vote', ['vote' => true])->assertNotFound();
        expect(SuggestedMusic::count())->toBe(0);
    })->with([
        'wrong code' => '/api/v1/app/invitation/xv-musica/NOPE00',
        'unknown slug' => '/api/v1/app/invitation/no-existe/SNG123',
        'companion code' => '/api/v1/app/invitation/xv-musica/CMP123',
    ]);

    it('answers 404 when the event is a draft', function () {
        songsEvent('premium', ['status' => 'draft']);

        $this->getJson(IS_URL . '/songs')->assertNotFound();
    });

    it('is still readable on an archived account event', function () {
        [$event, $main] = songsEvent('premium', ['status' => 'archived', 'purge_after' => now()->addDays(30)]);
        addSong($event, $main, 1);

        $this->getJson(IS_URL . '/songs')->assertOk()->assertJsonPath('data.0.title', 'Canción 1');
    });

    it('limits reads to 60 per minute per address', function () {
        songsEvent();

        foreach (range(1, 60) as $i) {
            $this->getJson(IS_URL . '/songs')->assertOk();
        }
        $this->getJson(IS_URL . '/songs')->assertStatus(429);
    });
});

describe('GET invitation songs/search', function () {
    it('returns the tracks with only the contract fields, never the credentials', function () {
        fakeSpotifyCatalogue();
        songsEvent();

        $response = $this->getJson(IS_URL . '/songs/search?q=shakira&limit=3')->assertOk();

        expect($response->json('data.tracks.items.0'))->toBe([
            'id' => 't1', 'name' => 'Song', 'uri' => 'spotify:track:t1', 'duration_ms' => 1000, 'preview_url' => null,
            'external_urls' => ['spotify' => 'https://open.spotify.com/track/t1'],
            'artists' => [['name' => 'Artist']],
            'album' => ['name' => 'Album', 'images' => [['url' => 'https://img/1']]],
        ])->and($response->getContent())->not->toContain('tok-secret')->not->toContain('test-secret');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.spotify.com') && $r['limit'] === 3 && $r['q'] === 'shakira');
    });

    it('caches the same query for the next guests', function () {
        fakeSpotifyCatalogue();
        songsEvent();

        $this->getJson(IS_URL . '/songs/search?q=Shakira')->assertOk();
        $this->getJson(IS_URL . '/songs/search?q=%20shakira%20')->assertOk();

        Http::assertSentCount(2); // one token + one search
    });

    it('uses the organizer search limit by default, capped at 10', function () {
        fakeSpotifyCatalogue();
        songsEvent('premium', [], ['use_preview' => true, 'use_vote_system' => true, 'search_limit' => 50]);

        $this->getJson(IS_URL . '/songs/search?q=abc')->assertOk();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.spotify.com') && $r['limit'] === 10);
    });

    it('validates with codes', function (string $qs, string $field, string $code) {
        fakeSpotifyCatalogue();
        songsEvent();

        $this->getJson(IS_URL . '/songs/search' . $qs)->assertStatus(422)->assertJsonPath("errors.{$field}.0", $code);
        Http::assertNothingSent();
    })->with([
        'missing q' => ['', 'q', 'required'],
        'blank q' => ['?q=%20%20', 'q', 'required'],
        'long q' => ['?q=' . str_repeat('a', 101), 'q', 'too_long'],
        'limit 0' => ['?q=abc&limit=0', 'limit', 'invalid'],
        'limit 11' => ['?q=abc&limit=11', 'limit', 'invalid'],
        'limit text' => ['?q=abc&limit=x', 'limit', 'invalid'],
    ]);

    it('answers 503 spotify_unavailable when the catalogue fails or has no credentials', function () {
        Http::fake(['*' => Http::response([], 500)]);
        songsEvent();

        $this->getJson(IS_URL . '/songs/search?q=abc')->assertStatus(503)->assertJsonPath('code', 'spotify_unavailable');

        config(['services.spotify.client_secret' => '']);
        $this->getJson(IS_URL . '/songs/search?q=other')->assertStatus(503)->assertJsonPath('code', 'spotify_unavailable');
    });

    it('does not cache a failure', function () {
        Http::fake(['*' => Http::response([], 500)]);
        songsEvent();
        $this->getJson(IS_URL . '/songs/search?q=abc')->assertStatus(503);

        app()->forgetInstance(Illuminate\Http\Client\Factory::class);
        Http::clearResolvedInstances();
        fakeSpotifyCatalogue();
        $this->getJson(IS_URL . '/songs/search?q=abc')->assertOk();
    });

    it('is refused as read only once the owner deleted her account', function () {
        fakeSpotifyCatalogue();
        songsEvent('premium', ['status' => 'archived', 'purge_after' => now()->addDays(30)]);

        $this->getJson(IS_URL . '/songs/search?q=abc')->assertForbidden()->assertJsonPath('code', 'read_only');
        Http::assertNothingSent();
    });

    it('limits one link to 20 searches per 10 minutes, with Retry-After', function () {
        fakeSpotifyCatalogue();
        songsEvent();

        foreach (range(1, 20) as $i) {
            $this->getJson(IS_URL . "/songs/search?q=song{$i}")->assertOk();
            RateLimiter::clear('invitation-songs-search|ip:127.0.0.1'); // isolate the per-link budget from the per-IP one
        }

        $this->getJson(IS_URL . '/songs/search?q=otra')->assertStatus(429)->assertJsonPath('code', 'too_many_attempts')->assertHeader('Retry-After');
    });

    it('limits one address to 20 searches per minute', function () {
        fakeSpotifyCatalogue();
        [$event] = songsEvent();
        foreach (range(1, 3) as $i) {
            Guest::factory()->create(['event_id' => $event->id, 'parent_id' => null, 'code' => "LNK00{$i}"]);
        }

        foreach (range(1, 20) as $i) {
            $this->getJson('/api/v1/app/invitation/xv-musica/LNK00' . (($i % 3) + 1) . "/songs/search?q=song{$i}")->assertOk();
        }
        $this->getJson('/api/v1/app/invitation/xv-musica/LNK001/songs/search?q=otra')->assertStatus(429)->assertHeader('Retry-After');
    });
});

describe('POST invitation songs', function () {
    it('suggests a song under the guest and returns it as listed', function () {
        [$event, $main] = songsEvent();

        $response = $this->postJson(IS_URL . '/songs', songBody(1, ['previewUrl' => 'https://p.scdn.co/mp3-preview/abc']))->assertCreated();

        expect(array_keys($response->json('data')))->toBe(['id', 'title', 'artist', 'thumbnailUrl', 'previewUrl', 'votes', 'votedByMe', 'suggestedByMe'])
            ->and($response->json('data'))->toMatchArray(['title' => 'Canción 1', 'votes' => 0, 'votedByMe' => false, 'suggestedByMe' => true, 'previewUrl' => 'https://p.scdn.co/mp3-preview/abc']);

        $stored = SuggestedMusic::first();
        expect($stored->event_id)->toBe($event->id)
            ->and($stored->suggested_by_entity)->toBe('guest')
            ->and($stored->suggested_by_id)->toBe($main->id)
            ->and($stored->platform)->toBe('spotify');
        $this->assertDatabaseHas('event_activities', ['event_id' => $event->id, 'type' => 'music_suggested']);
    });

    it('accepts a song without album, cover or preview', function () {
        songsEvent();

        $this->postJson(IS_URL . '/songs', ['platformId' => spotifyId(1), 'title' => 'Solo', 'artist' => 'Yo'])->assertCreated()
            ->assertJsonPath('data.thumbnailUrl', null);
    });

    it('validates the song with codes', function (array $body, string $field, string $code) {
        songsEvent();

        $this->postJson(IS_URL . '/songs', array_merge(songBody(1), $body))->assertStatus(422)->assertJsonPath("errors.{$field}.0", $code);
        expect(SuggestedMusic::count())->toBe(0);
    })->with([
        'no platformId' => [['platformId' => null], 'platformId', 'required'],
        'short platformId' => [['platformId' => 'abc'], 'platformId', 'invalid'],
        'platformId with symbols' => [['platformId' => str_repeat('a', 21) . '/'], 'platformId', 'invalid'],
        'no title' => [['title' => ''], 'title', 'required'],
        'long title' => [['title' => str_repeat('a', 256)], 'title', 'too_long'],
        'control chars in artist' => [['artist' => "Yo\x07"], 'artist', 'invalid'],
        'direction controls' => [['title' => "Can \u{202E}ción"], 'title', 'invalid'],
        'http cover' => [['thumbnailUrl' => 'http://i.scdn.co/image/1'], 'thumbnailUrl', 'invalid'],
        'foreign cover' => [['thumbnailUrl' => 'https://evil.example/x.jpg'], 'thumbnailUrl', 'invalid'],
        'lookalike host' => [['thumbnailUrl' => 'https://scdn.co.evil.example/x'], 'thumbnailUrl', 'invalid'],
        'credentials in url' => [['thumbnailUrl' => 'https://i.scdn.co@evil.example/x'], 'thumbnailUrl', 'invalid'],
        'javascript preview' => [['previewUrl' => 'javascript:alert(1)'], 'previewUrl', 'invalid'],
        'long preview' => [['previewUrl' => 'https://p.scdn.co/' . str_repeat('a', 500)], 'previewUrl', 'too_long'],
    ]);

    it('stores text as plain data, never interpreted', function () {
        songsEvent();

        $this->postJson(IS_URL . '/songs', songBody(1, ['title' => '<script>alert(1)</script>']))->assertCreated()
            ->assertJsonPath('data.title', '<script>alert(1)</script>');
    });

    it('answers 422 duplicate when the song was already suggested, by anyone', function () {
        [$event, $main] = songsEvent();
        $other = Guest::factory()->create(['event_id' => $event->id, 'parent_id' => null, 'code' => 'OTH999']);
        addSong($event, $other, 1);

        $this->postJson(IS_URL . '/songs', songBody(1))->assertStatus(422)->assertJsonPath('errors.duplicate.0', 'duplicate')->assertJsonPath('code', 'duplicate');
        expect(SuggestedMusic::count())->toBe(1);
    });

    it('allows the same song in another event', function () {
        songsEvent();
        $foreign = Events::factory()->create(['status' => 'published', 'custom_url_slug' => 'otro-evento']);
        addSong($foreign, null, 1);

        $this->postJson(IS_URL . '/songs', songBody(1))->assertCreated();
    });

    it('caps the suggestions at five per guest (the stored alias is what counts)', function () {
        [$event, $main] = songsEvent();
        $other = Guest::factory()->create(['event_id' => $event->id, 'parent_id' => null, 'code' => 'OTH999']);
        addSong($event, $other, 90);

        foreach (range(1, 5) as $i) {
            $this->postJson(IS_URL . '/songs', songBody($i))->assertCreated();
            RateLimiter::clear('invitation-songs-write-code|xv-musica|SNG123');
        }

        $this->postJson(IS_URL . '/songs', songBody(6))->assertStatus(422)->assertJsonPath('errors.limit.0', 'limit')->assertJsonPath('code', 'limit');
        expect(SuggestedMusic::where('suggested_by_id', $main->id)->count())->toBe(5);

        // Another guest has her own five; and removing one frees a place.
        $this->postJson('/api/v1/app/invitation/xv-musica/OTH999/songs', songBody(7))->assertCreated();
        $first = SuggestedMusic::where('suggested_by_id', $main->id)->first();
        $this->deleteJson(IS_URL . '/songs/' . $first->id)->assertNoContent();
        $this->postJson(IS_URL . '/songs', songBody(8))->assertCreated();
    });

    it('is refused as read only once the owner deleted her account', function () {
        songsEvent('premium', ['status' => 'archived', 'purge_after' => now()->addDays(30)]);

        $this->postJson(IS_URL . '/songs', songBody(1))->assertForbidden()->assertJsonPath('code', 'read_only');
        expect(SuggestedMusic::count())->toBe(0);
    });

    it('limits the writes of one link to 10 per 10 minutes, with Retry-After', function () {
        [$event, $main] = songsEvent();
        $max = (int) config('music.max_suggestions_per_guest');
        config(['music.max_suggestions_per_guest' => 50]);

        foreach (range(1, 10) as $i) {
            $this->postJson(IS_URL . '/songs', songBody($i))->assertCreated();
            RateLimiter::clear('invitation-songs-write|ip:127.0.0.1');
        }

        $this->postJson(IS_URL . '/songs', songBody(11))->assertStatus(429)->assertJsonPath('code', 'too_many_attempts')->assertHeader('Retry-After');
        expect(SuggestedMusic::count())->toBe(10)->and($max)->toBe(5);
    });

    it('limits the writes of one address to 10 per minute across links', function () {
        [$event] = songsEvent();
        config(['music.max_suggestions_per_guest' => 50]);
        foreach (range(1, 5) as $i) {
            Guest::factory()->create(['event_id' => $event->id, 'parent_id' => null, 'code' => "LNK00{$i}"]);
        }

        foreach (range(1, 10) as $i) {
            $this->postJson('/api/v1/app/invitation/xv-musica/LNK00' . (($i % 5) + 1) . '/songs', songBody($i))->assertCreated();
        }
        $this->postJson('/api/v1/app/invitation/xv-musica/LNK001/songs', songBody(11))->assertStatus(429);
    });

    it('does not count reads against the writing limits', function () {
        songsEvent();

        foreach (range(1, 12) as $i) {
            $this->getJson(IS_URL . '/songs')->assertOk();
        }
        $this->postJson(IS_URL . '/songs', songBody(1))->assertCreated();
    });
});

describe('DELETE invitation songs/{id}', function () {
    it('removes her own suggestion with its votes', function () {
        [$event, $main] = songsEvent();
        $song = addSong($event, $main, 1);
        SuggestedMusicVote::create(['suggested_music_id' => $song->id, 'guest_id' => $main->id, 'vote_type' => 'up']);

        $this->deleteJson(IS_URL . '/songs/' . $song->id)->assertNoContent();

        expect(SuggestedMusic::count())->toBe(0)->and(SuggestedMusicVote::count())->toBe(0);
    });

    it('answers 404 for a song of someone else, of the organizer, of another event, or missing', function () {
        [$event, $main] = songsEvent();
        $other = Guest::factory()->create(['event_id' => $event->id, 'parent_id' => null, 'code' => 'OTH999']);
        $foreign = Events::factory()->create(['status' => 'published', 'custom_url_slug' => 'otro-evento']);
        $ids = [addSong($event, $other, 1)->id, addSong($event, null, 2)->id, addSong($foreign, null, 3)->id, 99999];

        foreach ($ids as $id) {
            $this->deleteJson(IS_URL . '/songs/' . $id)->assertNotFound();
        }
        expect(SuggestedMusic::count())->toBe(3);
    });

    it('is refused as read only once the owner deleted her account', function () {
        [$event, $main] = songsEvent('premium', ['status' => 'archived', 'purge_after' => now()->addDays(30)]);
        $song = addSong($event, $main, 1);

        $this->deleteJson(IS_URL . '/songs/' . $song->id)->assertForbidden()->assertJsonPath('code', 'read_only');
        expect(SuggestedMusic::count())->toBe(1);
    });
});

describe('POST invitation songs/{id}/vote', function () {
    it('votes once per song, is idempotent, and removes the vote', function () {
        [$event, $main] = songsEvent();
        $song = addSong($event, null, 1);

        $first = $this->postJson(IS_URL . "/songs/{$song->id}/vote", ['vote' => true])->assertOk();
        expect($first->json('data'))->toMatchArray(['id' => $song->id, 'votes' => 1, 'votedByMe' => true])
            ->and($first->json('meta'))->toBe(['votesTotal' => 2, 'votesRemaining' => 1]);

        $this->postJson(IS_URL . "/songs/{$song->id}/vote", ['vote' => true])->assertOk()->assertJsonPath('data.votes', 1);
        expect(SuggestedMusicVote::count())->toBe(1);

        $this->postJson(IS_URL . "/songs/{$song->id}/vote", ['vote' => false])->assertOk()
            ->assertJsonPath('data.votes', 0)->assertJsonPath('data.votedByMe', false)->assertJsonPath('meta.votesRemaining', 2);
        expect(SuggestedMusicVote::count())->toBe(0);

        $this->postJson(IS_URL . "/songs/{$song->id}/vote", ['vote' => false])->assertOk()->assertJsonPath('data.votes', 0);
    });

    it('turns a legacy downvote into an upvote', function () {
        [$event, $main] = songsEvent();
        $song = addSong($event, null, 1);
        SuggestedMusicVote::create(['suggested_music_id' => $song->id, 'guest_id' => $main->id, 'vote_type' => 'down']);

        $this->postJson(IS_URL . "/songs/{$song->id}/vote", ['vote' => true])->assertOk()->assertJsonPath('data.votes', 1);
        expect(SuggestedMusicVote::first()->vote_type)->toBe('up');
    });

    it('spends a budget of one vote per person of the link (guest plus companions)', function () {
        [$event, $main] = songsEvent();
        $songs = collect(range(1, 3))->map(fn ($i) => addSong($event, null, $i));

        $this->postJson(IS_URL . "/songs/{$songs[0]->id}/vote", ['vote' => true])->assertOk();
        $this->postJson(IS_URL . "/songs/{$songs[1]->id}/vote", ['vote' => true])->assertOk()->assertJsonPath('meta.votesRemaining', 0);

        $this->postJson(IS_URL . "/songs/{$songs[2]->id}/vote", ['vote' => true])
            ->assertStatus(422)->assertJsonPath('errors.votes.0', 'votes_exhausted')->assertJsonPath('code', 'votes_exhausted');

        // Taking one back frees a vote; voting again on a song already voted is never refused.
        $this->postJson(IS_URL . "/songs/{$songs[0]->id}/vote", ['vote' => false])->assertOk();
        $this->postJson(IS_URL . "/songs/{$songs[2]->id}/vote", ['vote' => true])->assertOk();
        $this->postJson(IS_URL . "/songs/{$songs[1]->id}/vote", ['vote' => true])->assertOk();
        expect(SuggestedMusicVote::count())->toBe(2);
    });

    it('answers 403 votes_disabled when the organizer has no voting', function () {
        [$event] = songsEvent('premium', [], ['use_preview' => true, 'use_vote_system' => false]);
        $song = addSong($event, null, 1);

        $this->postJson(IS_URL . "/songs/{$song->id}/vote", ['vote' => true])->assertForbidden()->assertJsonPath('code', 'votes_disabled');
        expect(SuggestedMusicVote::count())->toBe(0);
    });

    it('answers 404 for a song of another event or missing', function () {
        songsEvent();
        $foreign = Events::factory()->create(['status' => 'published', 'custom_url_slug' => 'otro-evento']);
        $song = addSong($foreign, null, 1);

        $this->postJson(IS_URL . "/songs/{$song->id}/vote", ['vote' => true])->assertNotFound();
        $this->postJson(IS_URL . '/songs/99999/vote', ['vote' => true])->assertNotFound();
    });

    it('validates the vote flag', function (mixed $body, string $code) {
        [$event] = songsEvent();
        $song = addSong($event, null, 1);

        $this->postJson(IS_URL . "/songs/{$song->id}/vote", $body)->assertStatus(422)->assertJsonPath('errors.vote.0', $code);
    })->with([
        'missing' => [[], 'required'],
        'text' => [['vote' => 'yes'], 'invalid'],
        'number' => [['vote' => 1], 'invalid'],
    ]);

    it('is refused as read only once the owner deleted her account', function () {
        [$event] = songsEvent('premium', ['status' => 'archived', 'purge_after' => now()->addDays(30)]);
        $song = addSong($event, null, 1);

        $this->postJson(IS_URL . "/songs/{$song->id}/vote", ['vote' => true])->assertForbidden()->assertJsonPath('code', 'read_only');
    });

    it('limits the votes of one link to 30 per 10 minutes', function () {
        [$event] = songsEvent();
        $song = addSong($event, null, 1);

        foreach (range(1, 30) as $i) {
            $this->postJson(IS_URL . "/songs/{$song->id}/vote", ['vote' => $i % 2 === 0])->assertOk();
            RateLimiter::clear('invitation-songs-vote|ip:127.0.0.1');
        }

        $this->postJson(IS_URL . "/songs/{$song->id}/vote", ['vote' => true])->assertStatus(429)->assertJsonPath('code', 'too_many_attempts')->assertHeader('Retry-After');
    });
});

it('registers the per-address limits of the songs routes', function () {
    expect(RouteServiceProvider::ROUTE_LIMITS['invitation-songs-read'])->toBe([60, 1])
        ->and(RouteServiceProvider::ROUTE_LIMITS['invitation-songs-search'])->toBe([20, 1])
        ->and(RouteServiceProvider::ROUTE_LIMITS['invitation-songs-write'])->toBe([10, 1])
        ->and(RouteServiceProvider::ROUTE_LIMITS['invitation-songs-vote'])->toBe([30, 1]);
});
