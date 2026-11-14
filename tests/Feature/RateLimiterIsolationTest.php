<?php

use App\Models\User;
use App\Providers\RouteServiceProvider;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

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
    foreach (range(1, 30) as $i) {
        expect($this->get('/api/v1/app/invitation/nope/NOPE/calendar.ics')->status())->not->toBe(429);
    }
    $this->get('/api/v1/app/invitation/nope/NOPE/calendar.ics')->assertStatus(429)->assertHeader('Retry-After');
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
    $limiter = app(Illuminate\Cache\RateLimiter::class)->limiter('collaborator-invites');
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

    $confirm = URL::temporarySignedRoute('auth.confirm.email', now()->subMinute(), ['user' => $user->id]);
    $this->postJson($confirm)->assertStatus(403)->assertJsonPath('code', 'invalid_signed_link');
});

it('translates the invalid signed link message', function () {
    $user = User::factory()->create();
    $expired = URL::temporarySignedRoute('auth.check.password', now()->subMinute(), ['user' => $user->id, 'token' => 'x']);

    $en = $this->withHeader('X-Locale', 'en')->postJson($expired)->json('message');
    $es = $this->withHeader('X-Locale', 'es')->postJson($expired)->json('message');

    expect($en)->toBe('The link is not valid or has expired.')->and($es)->not->toBe($en);
});
