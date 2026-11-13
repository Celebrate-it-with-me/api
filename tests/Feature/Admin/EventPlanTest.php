<?php

use App\Models\AdminAuditLog;
use App\Models\Coupon;
use App\Models\EventPlan;
use App\Models\EventPurchase;
use App\Models\Events;
use App\Models\EventType;
use App\Models\Guest;
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

function epStaff(string $role): User
{
    $u = User::factory()->create();
    $u->assignRole($role);

    return $u;
}

function epEvent(string $plan = 'premium', string $status = 'draft'): Events
{
    $factory = Events::factory();

    return ($status === 'published' ? $factory->published() : $factory->draft())->create([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', $plan)->value('id'),
    ]);
}

describe('permissions', function () {
    it('answers 401, 403 and superadmin-only', function () {
        $event = epEvent();
        $calls = [
            fn ($t) => $t->getJson("/api/v1/admin/events/{$event->id}/purchases"),
            fn ($t) => $t->patchJson("/api/v1/admin/events/{$event->id}/plan", ['planSlug' => 'basic', 'reason' => 'por prueba']),
        ];
        foreach ($calls as $call) {
            $call($this)->assertUnauthorized();
        }
        foreach ($calls as $call) {
            $call($this->actingAs(User::factory()->create()))->assertForbidden();
            $call($this->actingAs(epStaff('support')))->assertForbidden();
        }
        $this->actingAs(epStaff('superadmin'))->getJson("/api/v1/admin/events/{$event->id}/purchases")->assertOk();
    });
});

describe('purchases', function () {
    it('lists purchases without gateway ids and tells whether a refund is possible', function () {
        $event = epEvent('premium', 'draft');
        $admin = epStaff('superadmin');
        $buyer = User::factory()->create();
        $coupon = Coupon::create(['code' => 'FUND20', 'kind' => 'percent_off', 'value' => 20]);
        EventPurchase::create(['event_id' => $event->id, 'user_id' => $buyer->id, 'event_plan_id' => $event->event_plan_id, 'coupon_id' => $coupon->id,
            'gateway' => 'manual', 'gateway_session_id' => 'cs_secret', 'gateway_payment_id' => 'pi_secret', 'receipt_url' => 'https://x.test/r',
            'list_price_cents' => 9900, 'amount_cents' => 7900, 'currency' => 'USD', 'status' => 'paid', 'paid_at' => now(), 'notes' => 'Zelle', 'created_by' => $admin->id]);
        EventPurchase::create(['event_id' => $event->id, 'user_id' => $buyer->id, 'event_plan_id' => $event->event_plan_id, 'gateway' => 'fake',
            'list_price_cents' => 9900, 'amount_cents' => 9900, 'status' => 'pending']);

        $res = $this->actingAs($admin)->getJson("/api/v1/admin/events/{$event->id}/purchases")->assertOk();

        $res->assertJsonCount(2, 'data')->assertJsonPath('eventStatus', 'draft')
            ->assertJsonPath('data.1.coupon', 'FUND20')->assertJsonPath('data.1.registeredBy.id', $admin->id)
            ->assertJsonPath('data.1.refundable', true)->assertJsonPath('data.1.amountCents', 7900)
            ->assertJsonPath('data.0.refundable', false)->assertJsonPath('data.0.refundBlockedReason', 'not_paid');
        expect($res->getContent())->not->toContain('cs_secret')->not->toContain('pi_secret')->not->toContain('x.test');
    });

    it('blocks refunds while the event is published', function () {
        $event = epEvent('premium', 'published');
        EventPurchase::create(['event_id' => $event->id, 'user_id' => User::factory()->create()->id, 'event_plan_id' => $event->event_plan_id, 'gateway' => 'manual',
            'list_price_cents' => 9900, 'amount_cents' => 9900, 'status' => 'paid']);

        $this->actingAs(epStaff('superadmin'))->getJson("/api/v1/admin/events/{$event->id}/purchases")
            ->assertJsonPath('data.0.refundable', false)->assertJsonPath('data.0.refundBlockedReason', 'event_published');
    });

    it('does not run extra queries per purchase', function () {
        $event = epEvent();
        $buyer = User::factory()->create();
        foreach (range(1, 8) as $i) {
            EventPurchase::create(['event_id' => $event->id, 'user_id' => $buyer->id, 'event_plan_id' => $event->event_plan_id, 'gateway' => 'manual',
                'list_price_cents' => 1, 'amount_cents' => 1, 'status' => 'paid', 'created_by' => $buyer->id]);
        }
        $admin = epStaff('superadmin');
        DB::enableQueryLog();
        $this->actingAs($admin)->getJson("/api/v1/admin/events/{$event->id}/purchases")->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        expect($queries)->toBeLessThan(12);
    });
});

describe('change plan', function () {
    it('changes the plan, audits before and after, and requires a reason', function () {
        $event = epEvent('basic');
        $admin = epStaff('superadmin');
        $url = "/api/v1/admin/events/{$event->id}/plan";

        $this->actingAs($admin)->patchJson($url, ['planSlug' => 'premium'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAs($admin)->patchJson($url, ['planSlug' => 'premium', 'reason' => 'abc'])->assertUnprocessable();
        $this->actingAs($admin)->patchJson($url, ['planSlug' => 'nope', 'reason' => 'por prueba'])->assertUnprocessable()->assertJsonValidationErrors('planSlug');

        $this->actingAs($admin)->patchJson($url, ['planSlug' => 'premium', 'reason' => 'Cortesía del dueño'])
            ->assertOk()->assertJsonPath('data.plan.slug', 'premium')->assertJsonPath('data.plan.guestLimit', 300);

        expect($event->fresh()->eventPlan->slug)->toBe('premium');
        $log = AdminAuditLog::where('action', 'event.plan_change')->first();
        expect($log->event_id)->toBe($event->id)->and($log->reason)->toBe('Cortesía del dueño')
            ->and($log->before['plan'])->toBe('basic')->and($log->after['plan'])->toBe('premium');
    });

    it('rejects the same plan', function () {
        $event = epEvent('premium');
        $this->actingAs(epStaff('superadmin'))->patchJson("/api/v1/admin/events/{$event->id}/plan", ['planSlug' => 'premium', 'reason' => 'por prueba'])
            ->assertUnprocessable()->assertJsonValidationErrors('planSlug');
    });

    it('warns, without deleting anything, when the guests exceed the new limit', function () {
        $event = epEvent('premium');
        Guest::factory()->count(32)->create(['event_id' => $event->id]);
        $total = $event->guests()->count();

        $res = $this->actingAs(epStaff('superadmin'))->patchJson("/api/v1/admin/events/{$event->id}/plan", ['planSlug' => 'basic', 'reason' => 'Reembolso por fuera'])
            ->assertOk();

        expect($event->guests()->count())->toBe($total);
        $codes = collect($res->json('data.warnings'))->pluck('code');
        expect($codes)->toContain('guests_over_limit');
        expect(AdminAuditLog::where('action', 'event.plan_change')->first()->after['warnings'])->toContain('guests_over_limit');
    });
});
