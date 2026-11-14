<?php

use App\Http\Middleware\ImpersonationGuard;
use App\Models\EventFeature;
use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\EventUserRole;
use App\Models\User;
use App\Support\AdminPermissions;
use App\Support\ImpersonationRules;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route as RouteFacade;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->withoutMiddleware(ThrottleRequests::class);
    Mail::fake();
});

/** Every route behind the impersonation guard (the authenticated app group). */
function guardedAppRoutes(): array
{
    return collect(RouteFacade::getRoutes()->getRoutes())
        ->filter(fn (Route $r) => in_array('impersonation.guard', app('router')->gatherRouteMiddleware($r), true)
            || in_array(ImpersonationGuard::class, app('router')->gatherRouteMiddleware($r), true))
        ->values()->all();
}

function blkUrl(Route $route, int $eventId): string
{
    return '/' . preg_replace('/\{[^}]+\}/', '999999', str_replace('{event}', (string) $eventId, $route->uri()));
}

function blkMethod(Route $route): string
{
    return collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first();
}

/** @return array{0: User, 1: Events, 2: string, 3: string} owner, event, impersonation token, normal token */
function blkSetup($test): array
{
    $staff = User::factory()->create();
    $staff->assignRole(AdminPermissions::SUPPORT);
    $owner = User::factory()->create();
    $event = Events::factory()->draft()->create([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::orderBy('id')->value('id'),
    ]);
    EventFeature::create(['event_id' => $event->id]);
    app('auth')->forgetGuards();
    $imp = $test->postJson("/api/v1/admin/users/{$owner->id}/impersonate", ['reason' => 'Auditar bloqueos'], ['Authorization' => 'Bearer ' . $staff->createToken('t')->plainTextToken])
        ->assertCreated()->json('token');

    return [$owner, $event, $imp, $owner->createToken('normal')->plainTextToken];
}

function blkCall($test, string $method, string $url, string $token)
{
    app('auth')->forgetGuards();

    return $test->json($method, $url, [], ['Authorization' => 'Bearer ' . $token]);
}

function isBlockedResponse($response): bool
{
    return $response->getStatusCode() === 403 && $response->json('code') === 'impersonation_blocked';
}

describe('route classification', function () {
    it('covers every route of the authenticated app group, in exactly one list', function () {
        $routes = guardedAppRoutes();
        expect(count($routes))->toBeGreaterThan(100);

        $unclassified = $both = [];
        foreach ($routes as $route) {
            $key = ImpersonationRules::key($route);
            $inBlocked = in_array($key, ImpersonationRules::BLOCKED, true);
            $inAllowed = in_array($key, ImpersonationRules::ALLOWED, true);
            if (! $inBlocked && ! $inAllowed) {
                $unclassified[] = $key;
            }
            if ($inBlocked && $inAllowed) {
                $both[] = $key;
            }
        }

        expect($unclassified)->toBe([], 'New app routes must be classified in App\Support\ImpersonationRules (BLOCKED or ALLOWED)')
            ->and($both)->toBe([]);
    });

    it('has no stale entries for routes that no longer exist', function () {
        $keys = collect(guardedAppRoutes())->map(fn (Route $r) => ImpersonationRules::key($r))->all();

        expect(array_values(array_diff([...ImpersonationRules::BLOCKED, ...ImpersonationRules::ALLOWED], $keys)))->toBe([]);
    });

    it('blocks the sensitive families, by what each route does', function () {
        $mustBeBlocked = [
            'POST api/v1/app/user/update-password',
            'DELETE api/v1/app/event/{event}',
            'POST api/v1/app/event/{event}/purchase/checkout',
            'GET api/v1/app/event/{event}/purchase/quote',
            'GET api/v1/app/event/{event}/purchase/status',
            'GET api/v1/app/exports/download',
            'GET api/v1/app/event/{event}/rsvp/guests/download',
            'GET api/v1/app/event/{event}/menus/guests/download',
            'GET api/v1/app/event/{event}/budget/export',
            'POST api/v1/app/user/2fa/enable', 'POST api/v1/app/user/2fa/disable', 'POST api/v1/app/user/2fa/verify', 'POST api/v1/app/user/2fa/recovery-codes',
            'GET api/v1/app/user/2fa/setup', 'GET api/v1/app/user/2fa/status', 'GET api/v1/app/user/2fa/recovery-codes',
            'POST api/v1/app/logout',
            'POST api/v1/app/support',
            'POST api/v1/app/event/{event}/collaborators',
            'DELETE api/v1/app/event/{event}/collaborators/{user}',
            'GET api/v1/app/event/{event}/suggest-music/export',
            'POST api/v1/app/event/{event}/collaborators/invite/{id}/accept',
        ];

        // Exactly the blocked list: moving any blocked route to ALLOWED (or adding one) has to be a decision made here too.
        expect(array_values(array_diff($mustBeBlocked, ImpersonationRules::BLOCKED)))->toBe([])
            ->and(array_values(array_diff(ImpersonationRules::BLOCKED, $mustBeBlocked)))->toBe([]);
    });

    it('has no route with several methods besides the PUT/PATCH pair (the rules classify by the first method)', function () {
        $multi = [];
        foreach (guardedAppRoutes() as $route) {
            $methods = array_values(array_diff($route->methods(), ['HEAD']));
            sort($methods);
            if (count($methods) > 1 && $methods !== ['PATCH', 'PUT']) {
                $multi[] = implode('|', $methods) . ' ' . $route->uri();
            }
        }

        expect($multi)->toBe([]);
    });

    it('treats anything sensitive by name as blocked: no allowed route looks like password, payment, export or 2fa', function () {
        $suspicious = array_filter(ImpersonationRules::ALLOWED, fn ($k) => preg_match('#password|purchase|checkout|payment-method|refund|2fa|token|session|export|download|logout|delete-account|impersonat(?!ion/end)#i', $k)
            && ! str_contains($k, 'budget/items/{item}/payments'));

        expect(array_values($suspicious))->toBe([]);
    });
});

describe('with an impersonation token', function () {
    it('answers 403 impersonation_blocked on every blocked route and never on an allowed one', function () {
        [, $event, $imp] = blkSetup($this);
        $wrong = [];

        foreach (guardedAppRoutes() as $route) {
            $key = ImpersonationRules::key($route);
            $blocked = in_array($key, ImpersonationRules::BLOCKED, true);
            $response = blkCall($this, blkMethod($route), blkUrl($route, $event->id), $imp);

            if ($blocked !== isBlockedResponse($response)) {
                $wrong[] = "{$key} => {$response->getStatusCode()} " . ($blocked ? '(expected blocked)' : '(expected to pass the guard)');
            }
        }

        expect($wrong)->toBe([]);
    });

    it('gives the blocked answer a stable shape the app can tell apart from other 403s', function () {
        [, $event, $imp] = blkSetup($this);

        blkCall($this, 'POST', '/api/v1/app/user/update-password', $imp)
            ->assertForbidden()->assertExactJson(['message' => 'Esta acción no está permitida durante una sesión de soporte.', 'code' => 'impersonation_blocked']);
        // A different 403 (hydrating somebody else's data) does not carry the code.
        blkCall($this, 'GET', '/api/v1/app/user/hydrate/' . User::factory()->create()->id, $imp)->assertForbidden()->assertJsonMissingPath('code');
    });

    it('blocks a route nobody classified (fail closed) and leaves it open to normal tokens', function () {
        RouteFacade::middleware(['api', 'auth:sanctum', 'not.suspended', 'impersonation.guard'])->get('api/v1/app/zz-new-feature', fn () => response()->json(['ok' => true]));
        [, , $imp, $normal] = blkSetup($this);

        expect(isBlockedResponse(blkCall($this, 'GET', '/api/v1/app/zz-new-feature', $imp)))->toBeTrue();
        blkCall($this, 'GET', '/api/v1/app/zz-new-feature', $normal)->assertOk();
    });

    it('blocks by the route, not by a lookalike url', function () {
        [, $event, $imp] = blkSetup($this);

        // Trailing slash / different case resolve to the same route and are blocked too.
        expect(isBlockedResponse(blkCall($this, 'POST', '/api/v1/app/user/update-password/', $imp)))->toBeTrue();
    });

    it('lets ordinary work through: the event, its guests and the activity', function () {
        [$owner, $event, $imp] = blkSetup($this);
        EventUserRole::create(['event_id' => $event->id, 'user_id' => $owner->id, 'role_id' => Role::where('name', 'owner')->value('id')]);

        blkCall($this, 'GET', "/api/v1/app/event/{$event->id}", $imp)->assertOk();
        blkCall($this, 'GET', "/api/v1/app/event/{$event->id}/dashboard-logs", $imp)->assertOk();
        blkCall($this, 'GET', "/api/v1/app/user/hydrate/{$owner->id}", $imp)->assertOk();
    });
});

describe('with a normal token', function () {
    it('never meets the impersonation block', function () {
        [, $event, , $normal] = blkSetup($this);
        $blocked = [];

        foreach (guardedAppRoutes() as $route) {
            if (isBlockedResponse(blkCall($this, blkMethod($route), blkUrl($route, $event->id), $normal))) {
                $blocked[] = ImpersonationRules::key($route);
            }
        }

        expect($blocked)->toBe([]);
    });
});
