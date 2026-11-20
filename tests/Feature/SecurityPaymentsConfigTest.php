<?php

use App\Http\Services\Payments\GatewayEvent;
use App\Http\Services\Payments\PaymentSettlement;
use App\Models\AdminAuditLog;
use App\Models\EventPlan;
use App\Models\EventPurchase;
use App\Models\Events;
use App\Models\EventType;
use App\Models\EventUserRole;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Stripe\ApiRequestor;
use Tests\Support\FakeStripeHttp;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    config(['services.stripe.secret' => 'sk_test_dummy', 'services.stripe.automatic_tax' => false]);
    $this->stripe = new FakeStripeHttp;
    ApiRequestor::setHttpClient($this->stripe);
});

afterEach(fn () => ApiRequestor::setHttpClient(null));

function spcEvent(?User $owner = null): array
{
    $owner ??= User::factory()->create();
    $event = Events::factory()->draft()->create([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', 'basic')->value('id'),
    ]);
    EventUserRole::create(['event_id' => $event->id, 'user_id' => $owner->id, 'role_id' => Role::where('name', 'owner')->value('id')]);

    return [$event, $owner];
}

function spcPurchase(Events $event, User $user, string $gateway = 'stripe', string $session = 'cs_a'): EventPurchase
{
    return EventPurchase::create([
        'event_id' => $event->id, 'user_id' => $user->id, 'event_plan_id' => EventPlan::where('slug', 'premium')->value('id'), 'gateway' => $gateway,
        'list_price_cents' => 9900, 'amount_cents' => 9900, 'currency' => 'usd', 'status' => 'pending', 'gateway_session_id' => $session,
    ]);
}

function spcPaid(EventPurchase $p, string $id, ?int $amount = 9900, ?string $currency = 'usd'): GatewayEvent
{
    return new GatewayEvent(id: $id, type: 'checkout.session.completed', kind: GatewayEvent::PAID, sessionId: $p->gateway_session_id, reference: (string) $p->id, paymentId: 'pi_' . $id, amount: $amount, currency: $currency);
}

describe('settling two purchases of one event (P-6)', function () {
    it('takes the lock of the EVENT row before looking for another paid purchase', function () {
        [$event, $owner] = spcEvent();
        $first = spcPurchase($event, $owner, 'stripe', 'cs_1');

        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = strtolower($q->sql);
        });
        app(PaymentSettlement::class)->handle('stripe', spcPaid($first, 'evt_1'));

        $lock = collect($queries)->search(fn ($sql) => str_contains($sql, 'from "events"') && str_contains($sql, 'for update'));
        $check = collect($queries)->search(fn ($sql) => str_contains($sql, 'from "event_purchases"') && str_contains($sql, '"status"') && str_contains($sql, 'exists'));
        expect($lock)->not->toBeFalse()->and($check)->not->toBeFalse()->and($lock)->toBeLessThan($check);
    });

    it('activates one and refunds the second, with a single invoice-worthy payment', function () {
        [$event, $owner] = spcEvent();
        $a = spcPurchase($event, $owner, 'stripe', 'cs_a');
        $b = spcPurchase($event, $owner, 'stripe', 'cs_b');

        app(PaymentSettlement::class)->handle('stripe', spcPaid($a, 'evt_a'));
        app(PaymentSettlement::class)->handle('stripe', spcPaid($b, 'evt_b'));

        expect(EventPurchase::where('event_id', $event->id)->where('status', 'paid')->count())->toBe(1)
            ->and($a->fresh()->status)->toBe('paid')
            ->and($b->fresh())->status->toBe('refunded')->needs_review->toBeTrue()->failure_code->toBe('duplicate_payment');
    });
});

describe('a provider event without amount or currency (P-7)', function () {
    it('activates nothing and flags the purchase for review when the gateway is Stripe', function () {
        [$event, $owner] = spcEvent();

        foreach ([[null, 'usd'], [9900, null], [null, null]] as $i => [$amount, $currency]) {
            $p = spcPurchase($event, $owner, 'stripe', "cs_n$i");
            $outcome = app(PaymentSettlement::class)->handle('stripe', spcPaid($p, "evt_n$i", $amount, $currency));

            expect($outcome)->toBe('mismatch')
                ->and($p->fresh())->status->toBe('pending')->needs_review->toBeTrue()->failure_code->toBe('amount_mismatch');
        }
        expect($event->fresh()->eventPlan->slug)->toBe('basic');
    });

    it('still lets the stand-in gateway omit them on purpose', function () {
        [$event, $owner] = spcEvent();
        $p = spcPurchase($event, $owner, 'fake', 'cs_f');

        expect(app(PaymentSettlement::class)->handle('fake', spcPaid($p, 'evt_f', null, null)))->toBe('processed')
            ->and($p->fresh()->status)->toBe('paid');
    });
});

describe('recording a manual payment (P-8)', function () {
    function spcAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');

        return $admin;
    }

    it('refuses an event that is already unlocked with a clear 422, and records nothing', function () {
        [$event, $owner] = spcEvent();
        $event->update(['event_plan_id' => EventPlan::where('slug', 'premium')->value('id')]);

        $this->actingAsReauthed(spcAdmin())->postJson("/api/v1/admin/payments/events/{$event->id}/manual", ['userId' => $owner->id, 'amountCents' => 9900, 'reason' => 'Zelle received'])
            ->assertStatus(422)->assertJsonValidationErrors('event');

        expect(EventPurchase::count())->toBe(0);
    });

    it('caps the amount', function () {
        [$event, $owner] = spcEvent();

        $this->actingAsReauthed(spcAdmin())->postJson("/api/v1/admin/payments/events/{$event->id}/manual", ['userId' => $owner->id, 'amountCents' => 1000001, 'reason' => 'Zelle received'])
            ->assertStatus(422)->assertJsonValidationErrors('amountCents');
        $this->actingAsReauthed(spcAdmin())->postJson("/api/v1/admin/payments/events/{$event->id}/manual", ['userId' => $owner->id, 'amountCents' => 1000000, 'reason' => 'Zelle received'])
            ->assertCreated();
    });

    it('flags in the audit trail a buyer who is not on the event, and not a member', function () {
        [$event, $owner] = spcEvent();
        $stranger = User::factory()->create();

        $this->actingAsReauthed(spcAdmin())->postJson("/api/v1/admin/payments/events/{$event->id}/manual", ['userId' => $stranger->id, 'amountCents' => 9900, 'reason' => 'Zelle received'])->assertCreated();
        expect(AdminAuditLog::where('action', 'payment.manual')->firstOrFail()->after['warning'])->toBe('buyer_not_event_member');

        AdminAuditLog::query()->delete();
        [$event2] = spcEvent($owner);
        $this->actingAsReauthed(spcAdmin())->postJson("/api/v1/admin/payments/events/{$event2->id}/manual", ['userId' => $owner->id, 'amountCents' => 9900, 'reason' => 'Zelle received'])->assertCreated();
        expect(AdminAuditLog::where('action', 'payment.manual')->firstOrFail()->after)->not->toHaveKey('warning');
    });
});
