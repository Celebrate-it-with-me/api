<?php

use App\Models\Events;
use App\Models\Guest;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

it('has no unnamed throttle that shares its counter with other routes', function () {
    foreach (Route::getRoutes() as $route) {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware) || ! str_starts_with($middleware, ThrottleRequests::class . ':')) {
                if (is_string($middleware) && preg_match('/^throttle:(\d+),(\d+)(?:,(.+))?$/', $middleware, $m)) {
                    expect($m[3] ?? null)->not->toBeNull("{$route->uri()} uses {$middleware} without a prefix");
                }

                continue;
            }
            $arg = substr($middleware, strlen(ThrottleRequests::class) + 1);
            if (preg_match('/^(\d+),(\d+)(?:,(.+))?$/', $arg, $m)) {
                expect($m[3] ?? null)->not->toBeNull("{$route->uri()} uses {$middleware} without a prefix");
            }
        }
    }
});

it('keeps the 5 per minute of reset-password when other routes were hit first (anonymous)', function () {
    foreach (range(1, 5) as $i) {
        $this->getJson('/api/v1/public/system-status')->assertOk();
    }

    foreach (range(1, 5) as $i) {
        $this->postJson('/api/v1/app/reset-password', [])->assertStatus(422);
    }
    $this->postJson('/api/v1/app/reset-password', [])->assertStatus(429);

    // The exhausted route does not touch the others.
    $this->getJson('/api/v1/public/system-status')->assertOk();
});

it('enforces the original limit of a public route (31st calendar request is 429)', function () {
    // A valid code: hits do not count in the wrong-code brake, so this is the per-IP limit of the route itself.
    $event = Events::factory()->create(['status' => 'published', 'custom_url_slug' => 'xv-de-valentina', 'start_date' => '2026-12-25 19:00:00']);
    Guest::factory()->create(['event_id' => $event->id, 'code' => 'VAL001', 'parent_id' => null]);

    foreach (range(1, 30) as $i) {
        expect($this->get('/api/v1/app/invitation/xv-de-valentina/VAL001/calendar.ics')->status())->not->toBe(429);
    }
    $this->get('/api/v1/app/invitation/xv-de-valentina/VAL001/calendar.ics')->assertStatus(429)->assertHeader('Retry-After');
});

it('isolates the counters per route for an authenticated user', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum');

    foreach (range(1, 5) as $i) {
        expect($this->postJson('/api/v1/app/support', [])->status())->not->toBe(429);
    }
    $this->postJson('/api/v1/app/support', [])->assertStatus(429);

    expect($this->getJson('/api/v1/app/invitation/nope/NOPE')->status())->not->toBe(429);
    expect($this->postJson('/api/v1/app/reset-password', [])->status())->toBe(422);
});

it('keeps the collaborator invite limits exact (10 per minute per user)', function () {
    $limiter = app(RateLimiter::class)->limiter('collaborator-invites');
    $limits = $limiter(request());

    expect($limits[0]->maxAttempts)->toBe(10)->and($limits[1]->maxAttempts)->toBe(30)->and($limits[1]->decaySeconds)->toBe(86400);
});

it('defines every limit with the expected window', function () {
    expect(RouteServiceProvider::ROUTE_LIMITS['invoice-resend'])->toBe([5, 10])
        ->and(RouteServiceProvider::ROUTE_LIMITS['whatsapp-send'])->toBe([20, 1])
        ->and(RouteServiceProvider::ROUTE_LIMITS['reset-password'])->toBe([5, 1]);
});

it('answers 403 invalid_signed_link for an expired or tampered signed link, and works for a valid one', function () {
    $user = User::factory()->create();
    $params = ['user' => $user->id, 'token' => 'abc'];

    $expired = URL::temporarySignedRoute('auth.check.password', now()->subMinute(), $params);
    $this->postJson($expired)->assertStatus(403)->assertJsonPath('code', 'invalid_signed_link')
        ->assertJsonStructure(['message']);

    $valid = URL::temporarySignedRoute('auth.check.password', now()->addHour(), $params);
    $this->postJson(str_replace('token=abc', 'token=other', $valid))->assertStatus(403)->assertJsonPath('code', 'invalid_signed_link');

    $this->postJson($valid)->assertOk()->assertJsonPath('token', 'abc');
});

it('translates the invalid signed link message', function () {
    $user = User::factory()->create();
    $expired = URL::temporarySignedRoute('auth.check.password', now()->subMinute(), ['user' => $user->id, 'token' => 'x']);

    $en = $this->withHeader('X-Locale', 'en')->postJson($expired)->json('message');
    $es = $this->withHeader('X-Locale', 'es')->postJson($expired)->json('message');

    expect($en)->toBe('The link is not valid or has expired.')->and($es)->not->toBe($en);
});

it('defaults the global api limiter to 120 per minute and reads it from config', function () {
    expect(config('app.api_rate_limit_per_minute'))->toBe(120);
    Route::middleware(['api'])->get('/api/v1/__api-limited', fn () => response()->json(['ok' => true]));

    config(['app.api_rate_limit_per_minute' => 3]);
    foreach (range(1, 3) as $i) {
        $this->getJson('/api/v1/__api-limited')->assertOk();
    }
    $this->getJson('/api/v1/__api-limited')->assertStatus(429);
});

describe('reads of a signed-in user (B3)', function () {
    beforeEach(function () {
        Route::middleware(['api', 'auth:sanctum'])->group(function () {
            Route::get('/api/v1/__api-read', fn () => response()->json(['ok' => true]));
            Route::post('/api/v1/__api-write', fn () => response()->json(['ok' => true]));
        });
        Route::middleware(['api'])->get('/api/v1/__api-anon', fn () => response()->json(['ok' => true]));
    });

    it('gives them their own counter of 300 a minute by default', function () {
        expect(config('app.api_read_rate_limit_per_minute'))->toBe(300);
        Sanctum::actingAs(User::factory()->create());
        config(['app.api_rate_limit_per_minute' => 2]);

        // Well past the 120 (here 2) of writes: one Inicio is ~14 reads.
        foreach (range(1, 20) as $i) {
            $this->getJson('/api/v1/__api-read')->assertOk();
        }
    });

    it('stops reads at their own limit without spending the counter of writes', function () {
        Sanctum::actingAs(User::factory()->create());
        config(['app.api_read_rate_limit_per_minute' => 3, 'app.api_rate_limit_per_minute' => 2]);

        foreach (range(1, 3) as $i) {
            $this->getJson('/api/v1/__api-read')->assertOk();
        }
        $this->getJson('/api/v1/__api-read')->assertStatus(429)->assertJsonPath('code', 'too_many_attempts')->assertHeader('Retry-After');

        $this->postJson('/api/v1/__api-write')->assertOk();
        $this->postJson('/api/v1/__api-write')->assertOk();
        $this->postJson('/api/v1/__api-write')->assertStatus(429);
    });

    it('keeps writes on the global limit however many reads are left', function () {
        Sanctum::actingAs(User::factory()->create());
        config(['app.api_read_rate_limit_per_minute' => 100, 'app.api_rate_limit_per_minute' => 2]);

        $this->postJson('/api/v1/__api-write')->assertOk();
        $this->postJson('/api/v1/__api-write')->assertOk();
        $this->postJson('/api/v1/__api-write')->assertStatus(429);
        $this->getJson('/api/v1/__api-read')->assertOk();
    });

    it('counts each user apart', function () {
        config(['app.api_read_rate_limit_per_minute' => 1]);
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/__api-read')->assertOk();
        $this->getJson('/api/v1/__api-read')->assertStatus(429);

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/__api-read')->assertOk();
    });

    it('leaves anonymous reads on the global limit per IP', function () {
        config(['app.api_read_rate_limit_per_minute' => 100, 'app.api_rate_limit_per_minute' => 2]);

        $this->getJson('/api/v1/__api-anon')->assertOk();
        $this->getJson('/api/v1/__api-anon')->assertOk();
        $this->getJson('/api/v1/__api-anon')->assertStatus(429);
    });

    it('does not lift the named limits of sensitive routes', function () {
        config(['app.api_read_rate_limit_per_minute' => 1000, 'app.api_rate_limit_per_minute' => 1000]);
        foreach (range(1, 5) as $i) {
            $this->postJson('/api/v1/app/reset-password', [])->assertStatus(422);
        }
        $this->postJson('/api/v1/app/reset-password', [])->assertStatus(429);
    });
});

it('keeps the 429 of the global limiter readable, coded and with Retry-After', function () {
    Route::middleware(['api'])->get('/api/v1/__api-limited2', fn () => response()->json(['ok' => true]));
    config(['app.api_rate_limit_per_minute' => 1]);

    $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/__api-limited2')->assertOk();
    $r = $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/__api-limited2')->assertStatus(429)
        ->assertJsonPath('code', 'too_many_attempts')
        ->assertJsonPath('message', 'Too many attempts. Try again later.');
    expect((int) $r->headers->get('Retry-After'))->toBeGreaterThan(0);

    $this->withHeader('Accept-Language', 'es')->getJson('/api/v1/__api-limited2')->assertStatus(429)
        ->assertJsonPath('message', 'Demasiados intentos. Inténtalo de nuevo más tarde.');
});

it('answers every named limiter 429 with the same body as the global api limiter', function () {
    foreach (range(1, 5) as $i) {
        $this->postJson('/api/v1/app/reset-password', [])->assertStatus(422);
    }

    $this->postJson('/api/v1/app/reset-password', [])
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertExactJson(['message' => __('errors.too_many_attempts'), 'code' => 'too_many_attempts']);
});
