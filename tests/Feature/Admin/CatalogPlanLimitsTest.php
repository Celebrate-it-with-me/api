<?php

use App\Http\Services\Admin\DashboardMetrics;
use App\Http\Services\AppServices\Hydrate\EventCacheService;
use App\Http\Services\AppServices\PlanFeatures;
use App\Models\AdminAuditLog;
use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\Guest;
use App\Models\User;
use App\Support\AdminPermissions;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    User::role(AdminPermissions::STAFF_ROLES)->get()->each(fn (User $u) => $u->syncRoles([]));
});

function plStaff(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function plEvent(string $plan, int $guests = 0): Events
{
    $event = Events::factory()->draft()->create([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', $plan)->value('id'),
    ]);
    if ($guests) {
        Guest::factory()->count($guests)->create(['event_id' => $event->id, 'parent_id' => null]);
    }

    return $event;
}

const PL = '/api/v1/admin/catalog/plans/';

describe('permissions', function () {
    it('answers 401, 403, support reads impact only, superadmin edits', function () {
        $patch = ['maxGuests' => 80, 'reason' => 'Ajuste de prueba'];

        $this->patchJson(PL . 'basic', $patch)->assertUnauthorized();
        $this->getJson(PL . 'basic/impact?maxGuests=5')->assertUnauthorized();

        $user = User::factory()->create();
        $this->actingAs($user)->patchJson(PL . 'basic', $patch)->assertForbidden();
        $this->actingAs($user)->getJson(PL . 'basic/impact?maxGuests=5')->assertForbidden();

        $support = plStaff('support');
        $this->actingAs($support)->getJson(PL . 'basic/impact?maxGuests=5')->assertOk();
        $this->actingAs($support)->patchJson(PL . 'basic', $patch)->assertForbidden();

        $this->actingAs(plStaff('superadmin'))->patchJson(PL . 'basic', $patch)->assertOk();
    });
});

describe('update plan', function () {
    it('edits limit, name and description, audits and invalidates the hydration cache', function () {
        $admin = plStaff('superadmin');
        Cache::put('hydration.event_plans', 'stale', 3600);
        $before = (int) EventPlan::where('slug', 'basic')->value('max_guests');
        plEvent('basic', 3);
        plEvent('basic', 1);

        $res = $this->actingAs($admin)->patchJson(PL . 'basic', [
            'maxGuests' => 2, 'name' => 'Gratis', 'description' => 'Nueva', 'reason' => 'Bajamos el límite',
        ])->assertOk()->json('data');

        expect($res)->toMatchArray(['slug' => 'basic', 'name' => 'Gratis', 'maxGuests' => 2, 'isFullPlan' => false, 'eventsOverNewLimit' => 1])
            ->and($res)->toHaveKeys(['priceCents', 'eventsCount'])
            ->and(Cache::has('hydration.event_plans'))->toBeFalse()
            ->and(app(EventCacheService::class)->getEventPlans()->firstWhere('slug', 'basic')->max_guests)->toBe(2);

        $log = AdminAuditLog::where('action', 'catalog.plan_updated')->firstOrFail();
        expect($log->actor_id)->toBe($admin->id)->and($log->subject_type)->toBe('plan')
            ->and($log->reason)->toBe('Bajamos el límite')
            ->and($log->before['maxGuests'])->toBe($before)
            ->and($log->after['maxGuests'])->toBe(2)
            ->and($log->after['eventsOverNewLimit'])->toBe(1);
    });

    it('validates reason, values, existence and coherence', function () {
        $admin = plStaff('superadmin');
        $p = fn (string $slug, array $b) => $this->actingAs($admin)->patchJson(PL . $slug, $b);

        $p('basic', ['maxGuests' => 10])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $p('basic', ['maxGuests' => 10, 'reason' => 'abc'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $p('basic', ['maxGuests' => -1, 'reason' => 'Motivo válido'])->assertUnprocessable()->assertJsonValidationErrors('maxGuests');
        $p('basic', ['maxGuests' => 'x', 'reason' => 'Motivo válido'])->assertUnprocessable();
        $p('basic', ['reason' => 'Motivo válido'])->assertUnprocessable();
        $p('nope', ['maxGuests' => 10, 'reason' => 'Motivo válido'])->assertNotFound();

        // free plan above the full plan, or unlimited while the full plan is capped
        $full = (int) EventPlan::where('slug', 'premium')->value('max_guests');
        $p('basic', ['maxGuests' => $full + 1, 'reason' => 'Motivo válido'])->assertUnprocessable()->assertJsonPath('code', 'incoherent_plan_limits');
        $p('basic', ['maxGuests' => 0, 'reason' => 'Motivo válido'])->assertUnprocessable();
        $p('premium', ['maxGuests' => $full + 50, 'reason' => 'Motivo válido'])->assertOk();
        // lowering the full plan under the free one is refused
        $basic = (int) EventPlan::where('slug', 'basic')->value('max_guests');
        $p('premium', ['maxGuests' => max(1, $basic - 1), 'reason' => 'Motivo válido'])->assertUnprocessable();
        // unlimited full plan lifts the cap; lux stays editable
        $p('premium', ['maxGuests' => 0, 'reason' => 'Motivo válido'])->assertOk();
        $p('basic', ['maxGuests' => 0, 'reason' => 'Motivo válido'])->assertOk();
        $p('lux', ['name' => 'Lux 2', 'reason' => 'Motivo válido'])->assertOk();
        expect(EventPlan::where('slug', 'lux')->value('slug'))->toBe('lux');
        expect(AdminAuditLog::where('action', 'catalog.plan_updated')->count())->toBeGreaterThan(0);
    });
});

describe('plan impact', function () {
    it('counts events over the new limit and now over, without changing anything', function () {
        $limit = (int) EventPlan::where('slug', 'basic')->value('max_guests');
        plEvent('basic', 4);
        plEvent('basic', 1);
        plEvent('premium', 6);

        $data = $this->actingAs(plStaff('support'))->getJson(PL . 'basic/impact?maxGuests=2')->assertOk()->json('data');
        expect($data['eventsOverNewLimit'])->toBe(1)->and($data['eventsNowOver'])->toBe(0)
            ->and((int) EventPlan::where('slug', 'basic')->value('max_guests'))->toBe($limit);

        $this->actingAs(plStaff('support'))->getJson(PL . 'basic/impact?maxGuests=0')->assertOk()->assertJsonPath('data.eventsOverNewLimit', 0);
        $this->actingAs(plStaff('support'))->getJson(PL . 'basic/impact')->assertUnprocessable();
    });
});

describe('shared rules', function () {
    it('keeps isPremium and the dashboard free-plan rule as before', function () {
        $features = app(PlanFeatures::class);
        expect($features->isPremium(plEvent('basic')))->toBeFalse()
            ->and($features->isPremium(plEvent('premium')))->toBeTrue()
            ->and($features->isPremium(plEvent('lux')))->toBeTrue()
            ->and($features->entryPlanId())->toBe((int) EventPlan::where('slug', 'basic')->value('id'));

        $limit = (int) EventPlan::where('slug', 'basic')->value('max_guests');
        plEvent('basic', $limit + 1);
        plEvent('premium', $limit + 1);
        $attention = collect(app(DashboardMetrics::class)->build(includeInternal: true)['attention']);
        expect($attention->firstWhere('type', 'over_free_limit')['count'] ?? 0)->toBe(1);
    });
});
