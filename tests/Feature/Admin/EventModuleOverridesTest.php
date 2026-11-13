<?php

use App\Http\Services\AppServices\EventModules;
use App\Models\AdminAuditLog;
use App\Models\EventModuleOverride;
use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\EventUserRole;
use App\Models\Module;
use App\Models\User;
use App\Support\AdminPermissions;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    User::role(AdminPermissions::STAFF_ROLES)->get()->each(fn (User $u) => $u->syncRoles([]));
});

function moStaff(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function moEvent(?User $owner = null, string $plan = 'basic'): array
{
    $owner ??= User::factory()->create();
    $event = Events::factory()->draft()->create([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', $plan)->value('id'),
    ]);
    EventUserRole::create(['event_id' => $event->id, 'user_id' => $owner->id, 'role_id' => Role::where('name', 'owner')->value('id')]);

    return [$event, $owner];
}

function moStates(Events $event): array
{
    return collect(app(EventModules::class)->forEvent($event))->pluck('state', 'key')->all();
}

describe('permissions', function () {
    it('answers 401 to anonymous, 403 to organizers, and lets support and superadmin in', function () {
        [$event, $owner] = moEvent();
        $put = ['state' => 'active', 'reason' => 'Regalo por soporte'];

        $this->getJson("/api/v1/admin/events/{$event->id}/modules")->assertUnauthorized();
        $this->putJson("/api/v1/admin/events/{$event->id}/modules/menus", $put)->assertUnauthorized();
        $this->actingAs($owner)->getJson("/api/v1/admin/events/{$event->id}/modules")->assertForbidden();
        $this->actingAs($owner)->putJson("/api/v1/admin/events/{$event->id}/modules/menus", $put)->assertForbidden();
        $this->actingAs($owner)->deleteJson("/api/v1/admin/events/{$event->id}/modules/menus", ['reason' => 'Quitar regalo'])->assertForbidden();

        foreach (['support', 'superadmin'] as $role) {
            $staff = moStaff($role);
            $this->actingAs($staff)->getJson("/api/v1/admin/events/{$event->id}/modules")->assertOk();
            $this->actingAs($staff)->putJson("/api/v1/admin/events/{$event->id}/modules/menus", $put)->assertOk();
            $this->actingAs($staff)->deleteJson("/api/v1/admin/events/{$event->id}/modules/menus", ['reason' => 'Quitar regalo'])->assertOk();
        }
    });
});

describe('listing', function () {
    it('shows the catalog with base state, effective state and the exception', function () {
        [$event] = moEvent();
        $staff = moStaff('support');
        $this->actingAs($staff)->putJson("/api/v1/admin/events/{$event->id}/modules/menus", [
            'state' => 'active', 'expiresAt' => now()->addDays(7)->toIso8601String(), 'reason' => 'Regalo por queja',
        ])->assertOk();
        $this->actingAs($staff)->putJson("/api/v1/admin/events/{$event->id}/modules/budget", ['state' => 'locked', 'reason' => 'Abuso detectado'])->assertOk();

        $data = $this->actingAs($staff)->getJson("/api/v1/admin/events/{$event->id}/modules")->assertOk()->json('data');
        $byKey = collect($data['modules'])->keyBy('key');

        expect($data['giftsActive'])->toBe(1)->and($data['blockedActive'])->toBe(1)
            ->and($byKey['menus'])->toMatchArray(['baseState' => 'locked', 'effectiveState' => 'active', 'source' => 'gift'])
            ->and($byKey['menus']['override'])->toMatchArray(['state' => 'active', 'reason' => 'Regalo por queja', 'createdBy' => ['id' => $staff->id, 'name' => $staff->name]])
            ->and($byKey['menus']['override']['expiresAt'])->not->toBeNull()
            ->and($byKey['budget'])->toMatchArray(['baseState' => 'active', 'effectiveState' => 'locked', 'source' => 'blocked'])
            ->and($byKey['rsvp'])->toMatchArray(['effectiveState' => 'active', 'source' => 'plan', 'override' => null])
            ->and($data['modules'][0])->toHaveKeys(['key', 'section', 'order']);
    });

    it('does not run more queries with many exceptions than with none', function () {
        $staff = moStaff('support');
        [$plain] = moEvent();
        [$busy] = moEvent();
        foreach (['menus', 'seating', 'comments', 'memories', 'budget'] as $key) {
            EventModuleOverride::create(['event_id' => $busy->id, 'module_key' => $key, 'state' => 'active', 'reason' => 'Prueba larga', 'created_by' => $staff->id]);
        }
        $this->actingAs($staff)->getJson("/api/v1/admin/events/{$plain->id}/modules")->assertOk();
        $queries = function (Events $e) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson("/api/v1/admin/events/{$e->id}/modules")->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        expect($queries($busy))->toBe($queries($plain));
    });
});

describe('writing', function () {
    it('requires a reason of at least 5 characters, a valid state and a future expiry', function () {
        [$event] = moEvent();
        $staff = moStaff('support');
        $url = "/api/v1/admin/events/{$event->id}/modules/menus";

        $this->actingAs($staff)->putJson($url, ['state' => 'active'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAs($staff)->putJson($url, ['state' => 'active', 'reason' => 'no'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAs($staff)->putJson($url, ['state' => 'maybe', 'reason' => 'Regalo valido'])->assertUnprocessable()->assertJsonValidationErrors('state');
        $this->actingAs($staff)->putJson($url, ['state' => 'active', 'reason' => 'Regalo valido', 'expiresAt' => now()->subDay()->toIso8601String()])
            ->assertUnprocessable()->assertJsonValidationErrors('expiresAt');
        expect(EventModuleOverride::count())->toBe(0);
    });

    it('rejects modules that do not exist or are disabled in the catalog', function () {
        [$event] = moEvent();
        $staff = moStaff('support');
        $body = ['state' => 'active', 'reason' => 'Regalo valido'];

        $this->actingAs($staff)->putJson("/api/v1/admin/events/{$event->id}/modules/nope", $body)->assertUnprocessable();
        $this->actingAs($staff)->putJson("/api/v1/admin/events/{$event->id}/modules/invitation", $body)->assertUnprocessable(); // switched off
    });

    it('keeps one exception per event and module, and audits before and after', function () {
        [$event] = moEvent();
        $staff = moStaff('support');
        $url = "/api/v1/admin/events/{$event->id}/modules/menus";

        $this->actingAs($staff)->putJson($url, ['state' => 'active', 'reason' => 'Primer regalo'])->assertOk();
        $this->actingAs($staff)->putJson($url, ['state' => 'locked', 'reason' => 'Cambio de decision'])->assertOk();

        expect(EventModuleOverride::count())->toBe(1);
        $logs = AdminAuditLog::where('action', 'event.module_override')->orderBy('id')->get();
        expect($logs)->toHaveCount(2)
            ->and($logs[0])->event_id->toBe($event->id)->actor_id->toBe($staff->id)->reason->toBe('Primer regalo')
            ->and($logs[0]->before)->toMatchArray(['module' => 'menus', 'override' => null, 'effectiveState' => 'locked'])
            ->and($logs[0]->after['override']['state'])->toBe('active')
            ->and($logs[0]->after['effectiveState'])->toBe('active')
            ->and($logs[1]->before['override']['state'])->toBe('active')
            ->and($logs[1]->after['effectiveState'])->toBe('locked');
    });

    it('removes an exception with a reason and audits it; removing nothing is a 404', function () {
        [$event] = moEvent();
        $staff = moStaff('superadmin');
        $url = "/api/v1/admin/events/{$event->id}/modules/menus";

        $this->actingAs($staff)->deleteJson($url, ['reason' => 'Quitar regalo'])->assertNotFound();
        $this->actingAs($staff)->putJson($url, ['state' => 'active', 'reason' => 'Regalo valido'])->assertOk();
        $this->actingAs($staff)->deleteJson($url)->assertUnprocessable()->assertJsonValidationErrors('reason');
        expect(EventModuleOverride::count())->toBe(1);

        $this->actingAs($staff)->deleteJson($url, ['reason' => 'Quitar regalo'])->assertOk();

        $log = AdminAuditLog::where('action', 'event.module_override_removed')->sole();
        expect(EventModuleOverride::count())->toBe(0)
            ->and($log->before['override']['state'])->toBe('active')->and($log->before['effectiveState'])->toBe('active')
            ->and($log->after)->toMatchArray(['override' => null, 'effectiveState' => 'locked'])
            ->and($log->reason)->toBe('Quitar regalo');
    });
});

describe('what the app sees', function () {
    it('shows a gifted module as active and a hand-locked one as locked, and goes back when removed', function () {
        [$event, $owner] = moEvent();
        $staff = moStaff('support');
        $modules = fn () => collect($this->actingAs($owner)->getJson("/api/v1/app/event/{$event->id}")->assertOk()->json('data.modules'))->pluck('state', 'key');

        expect($modules()->all())->toMatchArray(['menus' => 'locked', 'budget' => 'active']);

        $this->actingAs($staff)->putJson("/api/v1/admin/events/{$event->id}/modules/menus", ['state' => 'active', 'reason' => 'Regalo valido'])->assertOk();
        $this->actingAs($staff)->putJson("/api/v1/admin/events/{$event->id}/modules/budget", ['state' => 'locked', 'reason' => 'Bloqueo manual'])->assertOk();
        expect($modules()->all())->toMatchArray(['menus' => 'active', 'budget' => 'locked', 'rsvp' => 'active', 'seating' => 'locked']);

        // The event list and the admin sheet agree.
        $list = $this->actingAs($owner)->getJson('/api/v1/app/events')->assertOk()->json('data.events.0.modules');
        expect(collect($list)->pluck('state', 'key')->all())->toMatchArray(['menus' => 'active', 'budget' => 'locked']);
        $sheet = $this->actingAs($staff)->getJson("/api/v1/admin/events/{$event->id}")->assertOk()->json('data.modules');
        expect(collect($sheet)->pluck('state', 'key')->all())->toMatchArray(['menus' => 'active', 'budget' => 'locked']);

        $this->actingAs($staff)->deleteJson("/api/v1/admin/events/{$event->id}/modules/menus", ['reason' => 'Fin del regalo'])->assertOk();
        $this->actingAs($staff)->deleteJson("/api/v1/admin/events/{$event->id}/modules/budget", ['reason' => 'Fin del bloqueo'])->assertOk();
        expect($modules()->all())->toMatchArray(['menus' => 'locked', 'budget' => 'active']);
    });

    it('does not leak to other events', function () {
        [$event] = moEvent();
        [$other] = moEvent();
        EventModuleOverride::create(['event_id' => $event->id, 'module_key' => 'menus', 'state' => 'active', 'reason' => 'Solo este']);

        expect(moStates($event)['menus'])->toBe('active')->and(moStates($other)['menus'])->toBe('locked');
    });
});

describe('expiry', function () {
    it('ignores an expired exception', function () {
        [$event] = moEvent();
        EventModuleOverride::create(['event_id' => $event->id, 'module_key' => 'menus', 'state' => 'active', 'reason' => 'Vencido', 'expires_at' => now()->subMinute()]);
        EventModuleOverride::create(['event_id' => $event->id, 'module_key' => 'seating', 'state' => 'active', 'reason' => 'Vigente', 'expires_at' => now()->addDay()]);

        expect(moStates($event))->toMatchArray(['menus' => 'locked', 'seating' => 'active']);
        $data = $this->actingAs(moStaff('support'))->getJson("/api/v1/admin/events/{$event->id}/modules")->json('data');
        expect($data['giftsActive'])->toBe(1);
    });

    it('can lock a module but a disabled one stays hidden whatever the exception says', function () {
        [$event] = moEvent();
        Module::where('key', 'menus')->update(['is_enabled' => false]);
        EventModuleOverride::create(['event_id' => $event->id, 'module_key' => 'menus', 'state' => 'active', 'reason' => 'Apagado']);

        expect(moStates($event))->not->toHaveKey('menus');
    });

    it('prunes exceptions that expired more than 30 days ago and nothing else', function () {
        [$event] = moEvent();
        $make = fn ($key, $expires) => EventModuleOverride::create(['event_id' => $event->id, 'module_key' => $key, 'state' => 'active', 'reason' => 'Prueba larga', 'expires_at' => $expires]);
        $make('menus', now()->subDays(31));
        $make('seating', now()->subDays(5));
        $make('comments', now()->addDays(5));
        $make('memories', null);

        Artisan::call('modules:prune-overrides');

        expect(EventModuleOverride::pluck('module_key')->sort()->values()->all())->toBe(['comments', 'memories', 'seating']);
    });
});
