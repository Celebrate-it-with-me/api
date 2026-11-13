<?php

use App\Http\Services\AppServices\EventModules;
use App\Models\AdminAuditLog;
use App\Models\EventModuleOverride;
use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\Module;
use App\Models\User;
use App\Support\AdminPermissions;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    User::role(AdminPermissions::STAFF_ROLES)->get()->each(fn (User $u) => $u->syncRoles([]));
});

function catStaff(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function catEvent(string $plan): Events
{
    return Events::factory()->draft()->create([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', $plan)->value('id'),
    ]);
}

/** A module that only premium includes, so it is locked for basic events. */
function catPremiumOnlyModule(): string
{
    $module = Module::query()->enabled()->whereHas('plans', fn ($q) => $q->where('slug', 'premium'))->whereDoesntHave('plans', fn ($q) => $q->where('slug', 'basic'))->firstOrFail();

    return $module->key;
}

function catState(Events $event, string $key): ?string
{
    app(EventModules::class)->forget();

    return app(EventModules::class)->stateOf($event->fresh(), $key);
}

describe('permissions', function () {
    it('answers 401 to anonymous, 403 to organizers, support reads but cannot write, superadmin does everything', function () {
        $key = catPremiumOnlyModule();
        $put = ['planSlugs' => ['premium'], 'reason' => 'Cambio de prueba'];
        $patch = ['isEnabled' => true, 'reason' => 'Cambio de prueba'];
        $impact = "/api/v1/admin/catalog/impact?module={$key}&planSlugs[]=premium";

        $this->getJson('/api/v1/admin/catalog')->assertUnauthorized();
        $this->putJson("/api/v1/admin/catalog/modules/{$key}/plans", $put)->assertUnauthorized();

        $organizer = User::factory()->create();
        $this->actingAs($organizer)->getJson('/api/v1/admin/catalog')->assertForbidden();
        $this->actingAs($organizer)->getJson($impact)->assertForbidden();
        $this->actingAs($organizer)->putJson("/api/v1/admin/catalog/modules/{$key}/plans", $put)->assertForbidden();

        $support = catStaff('support');
        $this->actingAs($support)->getJson('/api/v1/admin/catalog')->assertOk();
        $this->actingAs($support)->getJson($impact)->assertOk();
        $this->actingAs($support)->putJson("/api/v1/admin/catalog/modules/{$key}/plans", $put)->assertForbidden();
        $this->actingAs($support)->patchJson("/api/v1/admin/catalog/modules/{$key}", $patch)->assertForbidden();

        $admin = catStaff('superadmin');
        $this->actingAs($admin)->getJson('/api/v1/admin/catalog')->assertOk();
        $this->actingAs($admin)->putJson("/api/v1/admin/catalog/modules/{$key}/plans", $put)->assertOk();
        $this->actingAs($admin)->patchJson("/api/v1/admin/catalog/modules/{$key}", $patch)->assertOk();
    });
});

describe('overview', function () {
    it('lists modules with their plans, and plans with their limits', function () {
        $key = catPremiumOnlyModule();
        catEvent('premium');
        catEvent('premium');
        catEvent('basic');
        $event = catEvent('basic');
        EventModuleOverride::create(['event_id' => $event->id, 'module_key' => $key, 'state' => 'active', 'reason' => 'Regalo', 'created_by' => null]);

        $data = $this->actingAs(catStaff('support'))->getJson('/api/v1/admin/catalog')->assertOk()->json('data');

        $module = collect($data['modules'])->firstWhere('key', $key);
        expect($module['isEnabled'])->toBeTrue()
            ->and($module['planSlugs'])->toContain('premium')->not->toContain('basic')
            ->and($module['eventsByPlan']['premium'])->toBe(2)
            ->and($module['overrides'])->toBe(['active' => 1, 'locked' => 0])
            ->and($module)->toHaveKeys(['section', 'order']);

        $premium = collect($data['plans'])->firstWhere('slug', 'premium');
        expect($premium)->toHaveKeys(['name', 'maxGuests', 'priceCents', 'isFullPlan', 'eventsCount'])
            ->and($premium['isFullPlan'])->toBeTrue()
            ->and($premium['eventsCount'])->toBe(2)
            ->and(collect($data['plans'])->firstWhere('slug', 'basic')['isFullPlan'])->toBeFalse();
    });

    it('does not run more queries when there are more events and exceptions', function () {
        $key = catPremiumOnlyModule();
        $admin = catStaff('support');
        $count = function () use ($admin) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($admin)->getJson('/api/v1/admin/catalog')->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $count(); // warm up (permissions cache)
        $before = $count();

        foreach (range(1, 6) as $i) {
            $e = catEvent($i % 2 ? 'basic' : 'premium');
            EventModuleOverride::create(['event_id' => $e->id, 'module_key' => $key, 'state' => 'locked', 'reason' => 'Bloqueo', 'created_by' => null]);
        }

        expect($count())->toBe($before);
    });
});

describe('impact', function () {
    it('counts the events that would lock or unlock, ignoring those with an exception', function () {
        $key = catPremiumOnlyModule();
        catEvent('premium');
        $kept = catEvent('premium');
        catEvent('basic');
        catEvent('basic');
        EventModuleOverride::create(['event_id' => $kept->id, 'module_key' => $key, 'state' => 'active', 'reason' => 'Regalo', 'created_by' => null]);

        // premium keeps it, lux (if it had it) and basic get it: basic is added.
        $current = Module::where('key', $key)->first()->plans()->pluck('slug')->all();
        $data = $this->actingAs(catStaff('support'))
            ->getJson('/api/v1/admin/catalog/impact?' . http_build_query(['module' => $key, 'planSlugs' => array_values(array_unique([...$current, 'basic']))]))
            ->assertOk()->json('data');

        $basic = collect($data['plans'])->firstWhere('slug', 'basic');
        expect($basic['change'])->toBe('added')->and($basic['toActive'])->toBe(2)->and($basic['toLocked'])->toBe(0)
            ->and($data['totalToActive'])->toBe(2)->and($data['totalToLocked'])->toBe(0)
            ->and($data['removesFromFullPlan'])->toBeFalse();

        // Removing from lux/non-full plan: premium loses one event (the other has an exception).
        $other = Module::query()->enabled()->whereHas('plans', fn ($q) => $q->where('slug', 'basic'))->first();
        $e = catEvent('basic');
        $planSlugs = $other->plans()->pluck('slug')->reject(fn ($s) => $s === 'basic')->values()->all();
        $out = $this->actingAs(catStaff('support'))
            ->getJson('/api/v1/admin/catalog/impact?' . http_build_query(['module' => $other->key, 'planSlugs' => $planSlugs]))
            ->assertOk()->json('data');
        $row = collect($out['plans'])->firstWhere('slug', 'basic');
        expect($row['change'])->toBe('removed')->and($row['toLocked'])->toBe(3)->and($row['events'])->toBe(3);
        expect($e)->not->toBeNull();
    });

    it('accepts an empty or absent planSlugs as "no plan at all" and counts what each current plan would lose', function () {
        $key = catPremiumOnlyModule();
        catEvent('premium');
        $kept = catEvent('premium');
        EventModuleOverride::create(['event_id' => $kept->id, 'module_key' => $key, 'state' => 'active', 'reason' => 'Regalo', 'created_by' => null]);
        $current = Module::where('key', $key)->first()->plans()->pluck('slug')->sort()->values()->all();
        $admin = catStaff('support');

        foreach (["/api/v1/admin/catalog/impact?module={$key}", "/api/v1/admin/catalog/impact?module={$key}&planSlugs="] as $url) {
            $data = $this->actingAs($admin)->getJson($url)->assertOk()->json('data');

            expect($data['planSlugs'])->toBe([])
                ->and($data['currentPlanSlugs'])->toBe($current)
                ->and(collect($data['plans'])->pluck('change')->unique()->all())->toBe(['removed'])
                ->and(collect($data['plans'])->pluck('slug')->sort()->values()->all())->toBe($current)
                ->and(collect($data['plans'])->firstWhere('slug', 'premium')['toLocked'])->toBe(1)
                ->and(collect($data['plans'])->firstWhere('slug', 'premium')['withOverride'])->toBe(1)
                ->and($data['totalToLocked'])->toBe(1)
                ->and($data['totalToActive'])->toBe(0);
        }
    });

    it('validates the module and the plans', function () {
        $admin = catStaff('support');
        $this->actingAs($admin)->getJson('/api/v1/admin/catalog/impact?module=nope&planSlugs[]=basic')->assertUnprocessable();
        $this->actingAs($admin)->getJson('/api/v1/admin/catalog/impact?module=' . catPremiumOnlyModule() . '&planSlugs[]=ghost')->assertUnprocessable();
    });
});

describe('changing the plans of a module', function () {
    it('requires a reason and valid plans', function () {
        $key = catPremiumOnlyModule();
        $admin = catStaff('superadmin');

        $this->actingAs($admin)->putJson("/api/v1/admin/catalog/modules/{$key}/plans", ['planSlugs' => ['premium']])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAs($admin)->putJson("/api/v1/admin/catalog/modules/{$key}/plans", ['planSlugs' => ['premium'], 'reason' => 'abc'])->assertUnprocessable();
        $this->actingAs($admin)->putJson("/api/v1/admin/catalog/modules/{$key}/plans", ['planSlugs' => ['ghost'], 'reason' => 'Motivo válido'])->assertUnprocessable();
        $this->actingAs($admin)->putJson('/api/v1/admin/catalog/modules/ghost/plans', ['planSlugs' => ['premium'], 'reason' => 'Motivo válido'])->assertNotFound();
    });

    it('adds a module to a plan so its events get it', function () {
        $key = catPremiumOnlyModule();
        $basicEvent = catEvent('basic');
        expect(catState($basicEvent, $key))->toBe('locked');

        $slugs = Module::where('key', $key)->first()->plans()->pluck('slug')->push('basic')->all();
        $data = $this->actingAs(catStaff('superadmin'))
            ->putJson("/api/v1/admin/catalog/modules/{$key}/plans", ['planSlugs' => $slugs, 'reason' => 'Ahora es gratis'])
            ->assertOk()->json('data');

        expect($data['module']['planSlugs'])->toContain('basic')->and($data['overridesCreated'])->toBe(0)
            ->and(catState($basicEvent, $key))->toBe('active');
    });

    it('keeps the module for existing events with an active exception by default', function () {
        $key = Module::query()->enabled()->whereHas('plans', fn ($q) => $q->where('slug', 'basic'))->whereHas('plans', fn ($q) => $q->where('slug', 'premium'))->value('key');
        $basic = catEvent('basic');
        $other = catEvent('basic');
        $gifted = catEvent('basic');
        $premium = catEvent('premium');
        EventModuleOverride::create(['event_id' => $gifted->id, 'module_key' => $key, 'state' => 'locked', 'reason' => 'Bloqueo manual', 'created_by' => null]);
        $admin = catStaff('superadmin');

        $slugs = Module::where('key', $key)->first()->plans()->pluck('slug')->reject(fn ($s) => $s === 'basic')->values()->all();
        $data = $this->actingAs($admin)
            ->putJson("/api/v1/admin/catalog/modules/{$key}/plans", ['planSlugs' => $slugs, 'reason' => 'Pasa a pago'])
            ->assertOk()->json('data');

        expect($data['overridesCreated'])->toBe(2)->and($data['eventsLocked'])->toBe(0)
            ->and(catState($basic, $key))->toBe('active')
            ->and(catState($other, $key))->toBe('active')
            ->and(catState($gifted, $key))->toBe('locked') // its own exception wins, untouched
            ->and(catState($premium, $key))->toBe('active');

        $o = EventModuleOverride::where('event_id', $basic->id)->where('module_key', $key)->first();
        expect($o->state)->toBe('active')->and($o->expires_at)->toBeNull()->and($o->reason)->toBe('Pasa a pago')->and($o->created_by)->toBe($admin->id);

        // A new basic event now sees it locked.
        expect(catState(catEvent('basic'), $key))->toBe('locked');
    });

    it('locks existing events when keepForExisting is false', function () {
        $key = Module::query()->enabled()->whereHas('plans', fn ($q) => $q->where('slug', 'basic'))->value('key');
        $event = catEvent('basic');
        $slugs = Module::where('key', $key)->first()->plans()->pluck('slug')->reject(fn ($s) => $s === 'basic')->values()->all();

        $data = $this->actingAs(catStaff('superadmin'))
            ->putJson("/api/v1/admin/catalog/modules/{$key}/plans", ['planSlugs' => $slugs, 'keepForExisting' => false, 'reason' => 'Sin conservar'])
            ->assertOk()->json('data');

        expect($data['overridesCreated'])->toBe(0)->and($data['eventsLocked'])->toBe(1)
            ->and(catState($event, $key))->toBe('locked')
            ->and(EventModuleOverride::where('module_key', $key)->count())->toBe(0);
    });

    it('replaces an expired exception when keeping the module', function () {
        $key = Module::query()->enabled()->whereHas('plans', fn ($q) => $q->where('slug', 'basic'))->value('key');
        $event = catEvent('basic');
        EventModuleOverride::create(['event_id' => $event->id, 'module_key' => $key, 'state' => 'locked', 'expires_at' => now()->subDay(), 'reason' => 'Vencida', 'created_by' => null]);
        $slugs = Module::where('key', $key)->first()->plans()->pluck('slug')->reject(fn ($s) => $s === 'basic')->values()->all();

        $this->actingAs(catStaff('superadmin'))
            ->putJson("/api/v1/admin/catalog/modules/{$key}/plans", ['planSlugs' => $slugs, 'reason' => 'Pasa a pago'])
            ->assertOk()->assertJsonPath('data.overridesCreated', 1);

        expect(catState($event, $key))->toBe('active');
    });

    it('refuses to take a module out of the full plan', function () {
        $key = catPremiumOnlyModule();
        $slugs = Module::where('key', $key)->first()->plans()->pluck('slug')->reject(fn ($s) => $s === 'premium')->values()->all();

        $this->actingAs(catStaff('superadmin'))
            ->putJson("/api/v1/admin/catalog/modules/{$key}/plans", ['planSlugs' => $slugs, 'reason' => 'Quitar de premium'])
            ->assertUnprocessable()->assertJsonPath('code', 'module_in_sold_plan');

        expect(Module::where('key', $key)->first()->plans()->where('slug', 'premium')->exists())->toBeTrue()
            ->and(AdminAuditLog::where('action', 'catalog.module_plans_updated')->count())->toBe(0);
    });

    it('lets a module have no plans at all', function () {
        // No seeded module is basic-only: build one.
        $module = Module::create(['key' => 'custom_service', 'section' => 'extras', 'sort_order' => 99, 'is_enabled' => true]);
        $module->plans()->sync([EventPlan::where('slug', 'basic')->value('id')]);
        $event = catEvent('basic');

        $data = $this->actingAs(catStaff('superadmin'))
            ->putJson('/api/v1/admin/catalog/modules/custom_service/plans', ['planSlugs' => [], 'reason' => 'A medida', 'keepForExisting' => false])
            ->assertOk()->json('data');

        expect($data['module']['planSlugs'])->toBe([])
            ->and(catState($event, 'custom_service'))->toBe('locked');

        $row = collect($this->actingAs(catStaff('support'))->getJson('/api/v1/admin/catalog')->json('data.modules'))->firstWhere('key', 'custom_service');
        expect($row['planSlugs'])->toBe([]);
    });

    it('audits the change with before, after, counts and reason', function () {
        $key = Module::query()->enabled()->whereHas('plans', fn ($q) => $q->where('slug', 'basic'))->value('key');
        catEvent('basic');
        $admin = catStaff('superadmin');
        $slugs = Module::where('key', $key)->first()->plans()->pluck('slug')->reject(fn ($s) => $s === 'basic')->values()->all();

        $this->actingAs($admin)->putJson("/api/v1/admin/catalog/modules/{$key}/plans", ['planSlugs' => $slugs, 'reason' => 'Pasa a pago'])->assertOk();

        $log = AdminAuditLog::where('action', 'catalog.module_plans_updated')->firstOrFail();
        expect($log->actor_id)->toBe($admin->id)
            ->and($log->subject_type)->toBe('module')
            ->and($log->reason)->toBe('Pasa a pago')
            ->and($log->before['planSlugs'])->toContain('basic')
            ->and($log->after['planSlugs'])->not->toContain('basic')
            ->and($log->after['overridesCreated'])->toBe(1)
            ->and($log->after['keepForExisting'])->toBeTrue();
    });
});

describe('turning a module on and off', function () {
    it('requires a reason', function () {
        $this->actingAs(catStaff('superadmin'))->patchJson('/api/v1/admin/catalog/modules/' . catPremiumOnlyModule(), ['isEnabled' => false])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
    });

    it('hides the module for everyone, reports orphan exceptions and audits it', function () {
        $key = catPremiumOnlyModule();
        $event = catEvent('premium');
        $gifted = catEvent('basic');
        EventModuleOverride::create(['event_id' => $gifted->id, 'module_key' => $key, 'state' => 'active', 'reason' => 'Regalo', 'created_by' => null]);
        $admin = catStaff('superadmin');

        $data = $this->actingAs($admin)->patchJson("/api/v1/admin/catalog/modules/{$key}", ['isEnabled' => false, 'reason' => 'En mantenimiento'])
            ->assertOk()->json('data');

        expect($data['module']['isEnabled'])->toBeFalse()->and($data['orphanOverrides'])->toBe(1)
            ->and(catState($event, $key))->toBeNull();

        $log = AdminAuditLog::where('action', 'catalog.module_toggled')->firstOrFail();
        expect($log->before)->toBe(['isEnabled' => true])->and($log->after['isEnabled'])->toBeFalse()
            ->and($log->after['orphanOverrides'])->toBe(1)->and($log->reason)->toBe('En mantenimiento');

        $this->actingAs($admin)->patchJson("/api/v1/admin/catalog/modules/{$key}", ['isEnabled' => true, 'reason' => 'Ya está listo'])->assertOk();
        expect(catState($event, $key))->toBe('active');
    });
});
