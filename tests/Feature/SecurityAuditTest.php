<?php

use App\Http\Middleware\EnsureEventAccess;
use App\Models\EventFeature;
use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\EventUserRole;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    // The audit makes hundreds of requests in a second: the rate limiter is not what is being checked here.
    $this->withoutMiddleware(ThrottleRequests::class);
});

/** Routes whose URI starts with the prefix, as [method, uri-with-placeholders, Route]. */
function auditRoutes(string $prefix): array
{
    $found = [];
    foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
        if (! str_starts_with($route->uri(), $prefix)) {
            continue;
        }
        $method = collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first();
        $found[] = [$method, $route->uri(), $route];
    }

    return $found;
}

/** The URL of a route for a given event: {event} is real, any other parameter is a made-up id. */
function auditUrl(string $uri, int $eventId): string
{
    $url = str_replace('{event}', (string) $eventId, $uri);

    return '/' . preg_replace('/\{[^}]+\}/', '999999', $url);
}

function auditEvent(): array
{
    $event = Events::factory()->draft()->create([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::orderBy('id')->value('id'),
    ]);
    EventFeature::create(['event_id' => $event->id]);
    $users = [];
    foreach (['owner', 'viewer'] as $role) {
        $users[$role] = User::factory()->create();
        EventUserRole::create(['event_id' => $event->id, 'user_id' => $users[$role]->id, 'role_id' => Role::where('name', $role)->value('id')]);
    }
    $users['stranger'] = User::factory()->create();

    return [$event, $users];
}

function guardedByEventAccess(Route $route): bool
{
    return collect(app('router')->gatherRouteMiddleware($route))->contains(fn ($m) => is_string($m) && str_starts_with($m, EnsureEventAccess::class));
}

/**
 * Routes under event/{event} that are deliberately NOT behind `event.access`: they are reached by someone who is not
 * (yet) a member. Anything new has to be added here on purpose.
 */
const OPEN_EVENT_ROUTES = [
    'api/v1/app/event/{event}/collaborators/invite/{token}',
    'api/v1/app/event/{event}/collaborators/invite/{id}/accept',
    'api/v1/app/event/{event}/collaborators/invite/{token}/decline',
];

describe('event routes', function () {
    it('are all behind the event access middleware, except the declared ones', function () {
        $open = [];
        foreach (auditRoutes('api/v1/app/event/{event}') as [$method, $uri, $route]) {
            if (! guardedByEventAccess($route) && ! in_array($uri, OPEN_EVENT_ROUTES, true)) {
                $open[] = "$method $uri";
            }
        }

        expect($open)->toBe([]);
    });

    it('answer 401 to someone who is not logged in', function () {
        [$event] = auditEvent();
        $wrong = [];
        foreach (auditRoutes('api/v1/app/event/{event}') as [$method, $uri]) {
            if (in_array($uri, OPEN_EVENT_ROUTES, true)) {
                continue; // reached through an invitation token, by someone who may have no account yet
            }
            $status = $this->json($method, auditUrl($uri, $event->id))->getStatusCode();
            if ($status !== 401) {
                $wrong[] = "$method $uri => $status";
            }
        }

        expect($wrong)->toBe([]);
    });

    it('answer 404 to a logged-in user who is not a member of the event', function () {
        [$event, $u] = auditEvent();
        $wrong = [];
        foreach (auditRoutes('api/v1/app/event/{event}') as [$method, $uri, $route]) {
            if (in_array($uri, OPEN_EVENT_ROUTES, true) || ! guardedByEventAccess($route)) {
                continue;
            }
            $status = $this->actingAs($u['stranger'])->json($method, auditUrl($uri, $event->id))->getStatusCode();
            if ($status !== 404) {
                $wrong[] = "$method $uri => $status";
            }
        }

        expect($wrong)->toBe([]);
    });

    it('do not let a viewer change anything', function () {
        [$event, $u] = auditEvent();
        $wrong = [];
        foreach (auditRoutes('api/v1/app/event/{event}') as [$method, $uri, $route]) {
            // Only the routes that need no other id: a made-up id is a 404 before permissions are looked at.
            $hasChild = preg_match('/\{(?!event\})[^}]+\}/', $uri);
            if (in_array($method, ['GET'], true) || $hasChild || in_array($uri, OPEN_EVENT_ROUTES, true) || ! guardedByEventAccess($route)) {
                continue;
            }
            $status = $this->actingAs($u['viewer'])->json($method, auditUrl($uri, $event->id))->getStatusCode();
            if ($status !== 403) {
                $wrong[] = "$method $uri => $status";
            }
        }

        expect($wrong)->toBe([]);
    });

    it('can be read by a viewer without a server error', function () {
        [$event, $u] = auditEvent();
        $wrong = [];
        foreach (auditRoutes('api/v1/app/event/{event}') as [$method, $uri, $route]) {
            $hasChild = preg_match('/\{(?!event\})[^}]+\}/', $uri);
            if ($method !== 'GET' || $hasChild || in_array($uri, OPEN_EVENT_ROUTES, true) || ! guardedByEventAccess($route)) {
                continue;
            }
            $status = $this->actingAs($u['viewer'])->json($method, auditUrl($uri, $event->id))->getStatusCode();
            if ($status >= 500) {
                $wrong[] = "$method $uri => $status";
            }
        }

        expect($wrong)->toBe([]);
    });
});

describe('routes outside an event', function () {
    it('only accept a user id that is the logged-in user (hydrate)', function () {
        $a = User::factory()->create();
        $b = User::factory()->create();

        $this->actingAs($a)->getJson("/api/v1/app/user/hydrate/{$b->id}")->assertStatus(403);
    });

    it('treat the admin routes as admin-only', function () {
        $user = User::factory()->create();
        $wrong = [];
        foreach (auditRoutes('api/v1/admin') as [$method, $uri]) {
            if (in_array($uri, ['api/v1/admin/login', 'api/v1/admin/login/two-factor'], true)) {
                continue;
            }
            $status = $this->actingAs($user)->json($method, '/' . preg_replace('/\{[^}]+\}/', '999999', $uri))->getStatusCode();
            if (! in_array($status, [401, 403, 404], true)) {
                $wrong[] = "$method $uri => $status";
            }
        }

        expect($wrong)->toBe([]);
    });

    it('treat the admin routes as closed to someone who is not logged in', function () {
        $wrong = [];
        foreach (auditRoutes('api/v1/admin') as [$method, $uri]) {
            if (in_array($uri, ['api/v1/admin/login', 'api/v1/admin/login/two-factor'], true)) {
                continue;
            }
            $status = $this->json($method, '/' . preg_replace('/\{[^}]+\}/', '999999', $uri))->getStatusCode();
            if (! in_array($status, [401, 403, 404], true)) {
                $wrong[] = "$method $uri => $status";
            }
        }

        expect($wrong)->toBe([]);
    });

    it('keep the guest-facing event routes closed without an access code', function () {
        [$event] = auditEvent();
        $wrong = [];
        foreach (auditRoutes('api/v1/app/template/event/{event}') as [$method, $uri]) {
            $status = $this->json($method, auditUrl($uri, $event->id))->getStatusCode();
            if ($status < 400 || $status >= 500) {
                $wrong[] = "$method $uri => $status";
            }
        }

        expect($wrong)->toBe([]);
    });
});

describe('admin panel', function () {
    it('lets an administrator in', function () {
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');

        $this->actingAs($admin)->getJson('/api/v1/admin/users')->assertOk();
        $this->actingAs($admin)->getJson('/api/v1/admin/userInfo')->assertOk();
    });

    it('gives an admin token to administrators only', function () {
        $admin = User::factory()->create(['password' => bcrypt('s3cret-pass')]);
        $admin->assignRole('superadmin');
        $regular = User::factory()->create(['password' => bcrypt('s3cret-pass')]);
        $login = fn (User $u) => $this->postJson('/api/v1/admin/login', ['email' => $u->email, 'password' => 's3cret-pass', 'device' => 'test']);

        $login($admin)->assertOk();
        $login($regular)->assertStatus(422)->assertJsonValidationErrors('email');
    });

    it('does not create an account with a known password from a migration', function () {
        // Admin accounts come from AdminUserSeeder, with credentials from the environment.
        foreach (glob(database_path('migrations/*.php')) as $file) {
            expect(file_get_contents($file))->not->toMatch('/Hash::make\(\s*[\'"]password[\'"]\s*\)/');
        }
    });
});

describe('hydration', function () {
    it('answers with your own data', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson("/api/v1/app/user/hydrate/{$user->id}")->assertOk();
    });
});
