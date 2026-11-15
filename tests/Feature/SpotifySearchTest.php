<?php

use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\EventUserRole;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    Cache::flush();
    config(['services.spotify.client_id' => 'test-id', 'services.spotify.client_secret' => 'test-secret']);
});

function spotifyEvent(string $plan = 'premium', string $role = 'owner'): array
{
    $event = Events::factory()->create([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', $plan)->value('id'),
    ]);
    $user = User::factory()->create();
    EventUserRole::create(['event_id' => $event->id, 'user_id' => $user->id, 'role_id' => Role::where('name', $role)->value('id')]);

    return [$event, $user];
}

function spotifyUrl(Events $event, string $qs = 'q=shakira'): string
{
    return "/api/v1/app/event/{$event->id}/suggest-music/spotify-search?{$qs}";
}

function fakeSpotify(): void
{
    Http::fake([
        'accounts.spotify.com/*' => Http::response(['access_token' => 'tok-1', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        'api.spotify.com/*' => Http::response(['tracks' => ['items' => [[
            'id' => 't1', 'name' => 'Song', 'uri' => 'spotify:track:t1', 'duration_ms' => 1000, 'preview_url' => null,
            'popularity' => 80, 'available_markets' => ['US'], 'href' => 'https://api.spotify.com/v1/tracks/t1',
            'external_urls' => ['spotify' => 'https://open.spotify.com/track/t1'],
            'artists' => [['id' => 'a1', 'name' => 'Artist', 'type' => 'artist']],
            'album' => ['name' => 'Album', 'release_date' => '2020', 'images' => [['url' => 'https://img/1', 'height' => 640, 'width' => 640]]],
        ]]]]),
    ]);
}

it('returns the tracks with only the contract fields', function () {
    fakeSpotify();
    [$event, $user] = spotifyEvent();
    Sanctum::actingAs($user);

    $response = $this->getJson(spotifyUrl($event, 'q=shakira&limit=3'))->assertOk();

    expect($response->json('data.tracks.items.0'))->toBe([
        'id' => 't1', 'name' => 'Song', 'uri' => 'spotify:track:t1', 'duration_ms' => 1000, 'preview_url' => null,
        'external_urls' => ['spotify' => 'https://open.spotify.com/track/t1'],
        'artists' => [['name' => 'Artist']],
        'album' => ['name' => 'Album', 'images' => [['url' => 'https://img/1']]],
    ]);
    Http::assertSent(fn ($r) => str_contains($r->url(), 'api.spotify.com/v1/search')
        && $r['q'] === 'shakira' && $r['type'] === 'track' && (int) $r['limit'] === 3 && $r->hasHeader('Authorization', 'Bearer tok-1'));
});

it('defaults the limit to 5', function () {
    fakeSpotify();
    [$event, $user] = spotifyEvent();
    Sanctum::actingAs($user);

    $this->getJson(spotifyUrl($event))->assertOk();

    Http::assertSent(fn ($r) => str_contains($r->url(), 'api.spotify.com') && (int) $r['limit'] === 5);
});

it('reuses the cached token between searches', function () {
    fakeSpotify();
    [$event, $user] = spotifyEvent();
    Sanctum::actingAs($user);

    $this->getJson(spotifyUrl($event))->assertOk();
    $this->getJson(spotifyUrl($event, 'q=other'))->assertOk();

    Http::assertSentCount(3); // 1 token + 2 searches
    expect(Http::recorded(fn ($r) => str_contains($r->url(), 'accounts.spotify.com')))->toHaveCount(1);
});

it('validates q and limit with codes', function (string $qs, string $field, string $code) {
    Http::fake();
    [$event, $user] = spotifyEvent();
    Sanctum::actingAs($user);

    $this->getJson(spotifyUrl($event, $qs))->assertUnprocessable()->assertJsonPath("errors.{$field}.0", $code);
    Http::assertNothingSent();
})->with([
    'missing q' => ['limit=5', 'q', 'required'],
    'blank q' => ['q=%20%20', 'q', 'required'],
    'long q' => [fn () => 'q=' . str_repeat('a', 101), 'q', 'too_long'],
    'limit zero' => ['q=a&limit=0', 'limit', 'invalid'],
    'limit 21' => ['q=a&limit=21', 'limit', 'invalid'],
    'limit text' => ['q=a&limit=abc', 'limit', 'invalid'],
]);

it('answers 503 without leaking anything when credentials are missing', function () {
    Http::fake();
    config(['services.spotify.client_id' => null, 'services.spotify.client_secret' => null]);
    [$event, $user] = spotifyEvent();
    Sanctum::actingAs($user);

    $this->getJson(spotifyUrl($event))->assertStatus(503)->assertJsonPath('code', 'spotify_unavailable');
    Http::assertNothingSent();
});

it('answers 503 without Spotify details when Spotify fails', function (string $which) {
    Http::fake($which === 'token'
        ? ['accounts.spotify.com/*' => Http::response(['error' => 'invalid_client', 'secret_hint' => 'LEAK'], 400)]
        : ['accounts.spotify.com/*' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            'api.spotify.com/*' => Http::response(['error' => ['message' => 'LEAK']], 500)]);
    [$event, $user] = spotifyEvent();
    Sanctum::actingAs($user);

    $response = $this->getJson(spotifyUrl($event))->assertStatus(503)->assertJsonPath('code', 'spotify_unavailable');
    expect($response->getContent())->not->toContain('LEAK');
})->with(['token', 'search']);

it('answers 503 when Spotify times out', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: timeout'));
    [$event, $user] = spotifyEvent();
    Sanctum::actingAs($user);

    $response = $this->getJson(spotifyUrl($event))->assertStatus(503)->assertJsonPath('code', 'spotify_unavailable');
    expect($response->getContent())->not->toContain('cURL');
});

it('is locked (403 module_locked) when the plan does not include suggested music', function () {
    Http::fake();
    [$event, $user] = spotifyEvent('basic');
    $event->eventPlan->modules()->detach();
    Sanctum::actingAs($user);

    $this->getJson(spotifyUrl($event))->assertForbidden()->assertJson(['code' => 'module_locked', 'module' => 'suggested_music']);
    Http::assertNothingSent();
});

it('answers 404 for an event the user does not belong to and 403 for a viewer', function () {
    Http::fake();
    [$event] = spotifyEvent();
    [, $stranger] = spotifyEvent();
    Sanctum::actingAs($stranger);
    $this->getJson(spotifyUrl($event))->assertNotFound();

    [$viewerEvent, $viewer] = spotifyEvent('premium', 'viewer');
    Sanctum::actingAs($viewer);
    $this->getJson(spotifyUrl($viewerEvent))->assertForbidden();
    Http::assertNothingSent();
});

it('requires authentication', function () {
    [$event] = spotifyEvent();
    $this->getJson(spotifyUrl($event))->assertUnauthorized();
});

it('throttles at 30 requests per minute', function () {
    fakeSpotify();
    [$event, $user] = spotifyEvent();
    Sanctum::actingAs($user);

    for ($i = 0; $i < 30; $i++) {
        $this->getJson(spotifyUrl($event))->assertOk();
    }
    $this->getJson(spotifyUrl($event))->assertStatus(429);
});
