<?php

use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\Guest;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    config(['app.frontend_app.url' => 'https://app.quinvite.test']);
});

function codeLimitEvent(string $slug = 'xv-de-valentina', string $code = 'VAL001'): array
{
    $event = Events::factory()->create([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', 'premium')->value('id'),
        'status' => 'published',
        'custom_url_slug' => $slug,
        'start_date' => '2026-12-25 19:00:00',
    ]);
    Guest::factory()->create(['event_id' => $event->id, 'code' => $code, 'parent_id' => null]);

    return [$event, $code];
}

function codeLimitRoute(array $route, string $slug, string $code): array
{
    return [$route[0], sprintf($route[1], $slug, $code)];
}

function codeLimitFromIp(string $ip, string $method, string $url)
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])->{$method}($url);
}

dataset('code-routes', [
    'invitation' => [['getJson', '/api/v1/app/invitation/%s/%s']],
    'calendar' => [['get', '/api/v1/app/invitation/%s/%s/calendar.ics']],
    'og' => [['get', '/og/%s/%s']],
]);

it('brakes wrong codes per IP and event, with a uniform 429', function (array $route) {
    codeLimitEvent();

    foreach (range(1, 10) as $i) {
        [$method, $url] = codeLimitRoute($route, 'xv-de-valentina', sprintf('BAD%03d', $i));
        codeLimitFromIp('10.0.0.1', $method, $url)->assertNotFound();
    }

    [$method, $url] = codeLimitRoute($route, 'xv-de-valentina', 'BAD999');
    $blocked = codeLimitFromIp('10.0.0.1', $method, $url)->assertStatus(429);
    expect($blocked->json('code'))->toBe('too_many_attempts');
    expect($blocked->headers->get('Retry-After'))->not->toBeNull();

    // The same IP is blocked even with the right code: the answer does not reveal whether it was right.
    [$method, $url] = codeLimitRoute($route, 'xv-de-valentina', 'VAL001');
    codeLimitFromIp('10.0.0.1', $method, $url)->assertStatus(429);
})->with('code-routes');

it('brakes rotating IPs per event', function (array $route) {
    codeLimitEvent();

    foreach (range(1, 20) as $i) {
        [$method, $url] = codeLimitRoute($route, 'xv-de-valentina', sprintf('BAD%03d', $i));
        codeLimitFromIp('10.0.1.' . $i, $method, $url)->assertNotFound();
    }

    [$method, $url] = codeLimitRoute($route, 'xv-de-valentina', 'BAD999');
    codeLimitFromIp('10.0.1.200', $method, $url)->assertStatus(429)->assertJsonPath('code', 'too_many_attempts');
    [$method, $url] = codeLimitRoute($route, 'xv-de-valentina', 'VAL001');
    codeLimitFromIp('10.0.1.201', $method, $url)->assertStatus(429);
})->with('code-routes');

it('does not cross limits between events', function () {
    codeLimitEvent();
    codeLimitEvent('boda-de-ana', 'ANA001');

    foreach (range(1, 20) as $i) {
        codeLimitFromIp('10.0.2.' . $i, 'getJson', '/api/v1/app/invitation/xv-de-valentina/' . sprintf('BAD%03d', $i))->assertNotFound();
    }

    codeLimitFromIp('10.0.2.1', 'getJson', '/api/v1/app/invitation/xv-de-valentina/VAL001')->assertStatus(429);
    codeLimitFromIp('10.0.2.1', 'getJson', '/api/v1/app/invitation/boda-de-ana/ANA001')->assertOk();
});

it('lets legitimate guests in as often as they like: hits do not use the budget', function () {
    codeLimitEvent();

    foreach (range(1, 40) as $_) {
        codeLimitFromIp('10.0.3.1', 'getJson', '/api/v1/app/invitation/xv-de-valentina/VAL001')->assertOk();
    }
    foreach (range(1, 5) as $i) {
        codeLimitFromIp('10.0.3.1', 'getJson', '/api/v1/app/invitation/xv-de-valentina/BAD00' . $i)->assertNotFound();
    }
    codeLimitFromIp('10.0.3.1', 'getJson', '/api/v1/app/invitation/xv-de-valentina/VAL001')->assertOk();
});

it('counts a wrong code and a wrong slug the same way', function () {
    codeLimitEvent();

    foreach (range(1, 10) as $i) {
        codeLimitFromIp('10.0.4.1', 'getJson', '/api/v1/app/invitation/nope/BAD00' . $i)->assertNotFound();
    }
    codeLimitFromIp('10.0.4.1', 'getJson', '/api/v1/app/invitation/nope/BAD099')->assertStatus(429);
});

it('brakes the guest-facing template routes by event', function () {
    [$event] = codeLimitEvent();
    $url = "/api/v1/app/template/event/{$event->id}/comments";

    foreach (range(1, 10) as $i) {
        codeLimitFromIp('10.0.5.1', 'getJson', "$url?accessCode=BAD00$i")->assertNotFound();
    }

    codeLimitFromIp('10.0.5.1', 'getJson', "$url?accessCode=BAD099")->assertStatus(429)->assertJsonPath('code', 'too_many_attempts');
    codeLimitFromIp('10.0.5.1', 'getJson', "$url?accessCode=VAL001")->assertStatus(429);
    codeLimitFromIp('10.0.5.2', 'getJson', "$url?accessCode=VAL001")->assertOk();
});

it('lets the budget go after the minute', function () {
    codeLimitEvent();

    foreach (range(1, 10) as $i) {
        codeLimitFromIp('10.0.6.1', 'getJson', '/api/v1/app/invitation/xv-de-valentina/BAD00' . $i)->assertNotFound();
    }
    codeLimitFromIp('10.0.6.1', 'getJson', '/api/v1/app/invitation/xv-de-valentina/VAL001')->assertStatus(429);

    $this->travel(61)->seconds();

    codeLimitFromIp('10.0.6.1', 'getJson', '/api/v1/app/invitation/xv-de-valentina/VAL001')->assertOk();
});

it('serves /og without session or cookies', function () {
    codeLimitEvent();

    $response = $this->get('/og/xv-de-valentina/VAL001')->assertOk();

    expect($response->headers->getCookies())->toBeEmpty();
});
