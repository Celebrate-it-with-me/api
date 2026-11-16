<?php

use App\Http\Services\Invoices\InvoiceService;
use App\Models\AdminAuditLog;
use App\Models\CreditNote;
use App\Models\EventPlan;
use App\Models\EventPurchase;
use App\Models\Events;
use App\Models\EventType;
use App\Models\EventUserRole;
use App\Models\Invoice;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Stripe\ApiRequestor;
use Tests\Support\FakeStripeHttp;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    config([
        'services.payments.gateway' => 'stripe', 'services.stripe.secret' => 'sk_test_dummy', 'services.stripe.webhook_secret' => 'whsec_adm',
    ]);
    $this->stripe = new FakeStripeHttp;
    ApiRequestor::setHttpClient($this->stripe);
});

afterEach(fn () => ApiRequestor::setHttpClient(null));

function admStaff(string $role = 'superadmin'): User
{
    $u = User::factory()->create();
    $u->assignRole($role);

    return $u;
}

/** A purchase as the webhook would have left it. */
function admPurchase(array $attrs = [], string $eventStatus = 'draft', bool $premium = true): EventPurchase
{
    $event = Events::factory()->create([
        'event_name' => 'Los XV de Ana', 'status' => $eventStatus, 'custom_url_slug' => 'ana-' . bin2hex(random_bytes(3)),
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', $premium ? 'premium' : 'basic')->value('id'),
    ]);

    $purchase = EventPurchase::create($attrs + [
        'event_id' => $event->id, 'user_id' => User::factory()->create()->id, 'event_plan_id' => EventPlan::where('slug', 'premium')->value('id'),
        'gateway' => 'stripe', 'list_price_cents' => 9900, 'amount_cents' => 9900, 'currency' => 'USD', 'status' => 'paid', 'paid_at' => now(),
        'gateway_session_id' => 'cs_adm_' . bin2hex(random_bytes(3)), 'gateway_payment_id' => 'pi_adm_1',
    ]);

    // What Stripe knows about its session: paid, if the purchase is.
    if ($purchase->gateway_session_id && ! isset(test()->stripe->sessions[$purchase->gateway_session_id])) {
        test()->stripe->sessions[$purchase->gateway_session_id] = [
            'id' => $purchase->gateway_session_id, 'object' => 'checkout.session', 'status' => 'complete', 'payment_status' => 'paid',
            'payment_intent' => $purchase->gateway_payment_id, 'amount_total' => 9900, 'currency' => 'usd', 'url' => null,
        ];
    }

    return $purchase;
}

describe('refund', function () {
    it('refunds for real: Stripe refund with an idempotency key, credit note, free plan, id stored and audited', function () {
        $p = admPurchase();
        app(InvoiceService::class)->issueForPurchase($p);

        $this->actingAsReauthed(admStaff())->postJson("/api/v1/admin/payments/{$p->id}/refund", ['reason' => 'Asked by the buyer'])
            ->assertOk()->assertJsonPath('data.status', 'refunded');

        $call = $this->stripe->callsTo('post', '#^/v1/refunds$#');
        expect($call)->toHaveCount(1)->and($call[0]['params']['payment_intent'])->toBe('pi_adm_1')
            ->and(implode('|', $call[0]['headers']))->toContain("Idempotency-Key: refund-{$p->id}")
            ->and($p->fresh())->status->toBe('refunded')->refund_reason->toBe('Asked by the buyer')->gateway_refund_id->toStartWith('re_test_')
            ->and($p->event->fresh()->eventPlan->slug)->toBe('basic')
            ->and(CreditNote::where('purchase_id', $p->id)->count())->toBe(1);
        $log = AdminAuditLog::where('action', 'payment.refund')->first();
        expect($log->reason)->toBe('Asked by the buyer')->and($log->after['gatewayRefundId'])->toStartWith('re_test_');
    });

    it('is full and only before publishing, and a second refund asks Stripe nothing', function () {
        $p = admPurchase([], 'published');
        $staff = admStaff();

        $this->actingAsReauthed($staff)->postJson("/api/v1/admin/payments/{$p->id}/refund", ['reason' => 'Asked by the buyer'])
            ->assertStatus(422)->assertJsonPath('errors.purchase.0', 'event_published');
        expect($this->stripe->callsTo('post', '#^/v1/refunds$#'))->toHaveCount(0);

        $p->event->update(['status' => 'draft']);
        $this->actingAsReauthed($staff)->postJson("/api/v1/admin/payments/{$p->id}/refund", ['reason' => 'Asked by the buyer'])->assertOk();
        $this->actingAsReauthed($staff)->postJson("/api/v1/admin/payments/{$p->id}/refund", ['reason' => 'Asked by the buyer'])
            ->assertStatus(422)->assertJsonPath('errors.purchase.0', 'not_paid');
        expect($this->stripe->callsTo('post', '#^/v1/refunds$#'))->toHaveCount(1);
    });

    it('answers 502 gateway_error and leaves everything as it was when Stripe fails', function () {
        $p = admPurchase();
        $this->stripe->failNext = true;

        $this->actingAsReauthed(admStaff())->postJson("/api/v1/admin/payments/{$p->id}/refund", ['reason' => 'Asked by the buyer'])
            ->assertStatus(502)->assertJsonPath('code', 'gateway_error');

        expect($p->fresh())->status->toBe('paid')->gateway_refund_id->toBeNull()
            ->and($p->event->fresh()->eventPlan->slug)->toBe('premium')
            ->and(AdminAuditLog::where('action', 'payment.refund')->count())->toBe(0)
            ->and(CreditNote::count())->toBe(0);
    });

    it('needs the refund permission, a recent re-authentication and a reason', function () {
        $p = admPurchase();

        $this->actingAsReauthed(admStaff('support'))->postJson("/api/v1/admin/payments/{$p->id}/refund", ['reason' => 'Asked by the buyer'])->assertForbidden();
        $this->actingAs(admStaff())->postJson("/api/v1/admin/payments/{$p->id}/refund", ['reason' => 'Asked by the buyer'])->assertStatus(403);
        $this->actingAsReauthed(admStaff())->postJson("/api/v1/admin/payments/{$p->id}/refund", [])->assertStatus(422)->assertJsonValidationErrors('reason');

        expect($this->stripe->calls)->toBe([])->and($p->fresh()->status)->toBe('paid');
    });
});

describe('sync', function () {
    it('catches a payment whose webhook never arrived and audits it', function () {
        $p = admPurchase(['status' => 'pending', 'paid_at' => null, 'gateway_payment_id' => null], premium: false);
        $this->stripe->sessions[$p->gateway_session_id] = [
            'id' => $p->gateway_session_id, 'object' => 'checkout.session', 'status' => 'complete', 'payment_status' => 'paid',
            'payment_intent' => 'pi_late', 'amount_total' => 9900, 'currency' => 'usd', 'url' => null,
        ];

        $this->actingAsReauthed(admStaff())->postJson("/api/v1/admin/payments/{$p->id}/sync", ['reason' => 'Buyer wrote in'])
            ->assertOk()->assertJsonPath('data.status', 'paid');

        expect($p->fresh())->status->toBe('paid')->gateway_payment_id->toBe('pi_late')
            ->and($p->event->fresh()->eventPlan->slug)->toBe('premium');
        $log = AdminAuditLog::where('action', 'payment.sync')->first();
        expect($log->reason)->toBe('Buyer wrote in')->and($log->before['status'])->toBe('pending')->and($log->after['status'])->toBe('paid');
    });

    it('applies a refund made in the Stripe dashboard (and flags it when the event is published)', function () {
        $p = admPurchase();
        $this->stripe->refunds[] = ['id' => 're_dash', 'payment_intent' => 'pi_adm_1', 'amount' => 9900, 'status' => 'succeeded'];

        $this->actingAsReauthed(admStaff())->postJson("/api/v1/admin/payments/{$p->id}/sync", ['reason' => 'Refund in dashboard'])->assertOk();

        expect($p->fresh())->status->toBe('refunded')->gateway_refund_id->toBe('re_dash')
            ->and($p->event->fresh()->eventPlan->slug)->toBe('basic');

        $pub = admPurchase(['gateway_payment_id' => 'pi_adm_pub'], 'published');
        $this->stripe->refunds[] = ['id' => 're_pub', 'payment_intent' => 'pi_adm_pub', 'amount' => 9900, 'status' => 'succeeded'];
        $this->actingAsReauthed(admStaff())->postJson("/api/v1/admin/payments/{$pub->id}/sync", ['reason' => 'Refund in dashboard'])->assertOk();
        expect($pub->fresh())->status->toBe('refunded')->needs_review->toBeTrue()->and($pub->event->fresh()->eventPlan->slug)->toBe('premium');
    });

    it('flags a partial refund instead of reversing the purchase', function () {
        $p = admPurchase();
        $this->stripe->refunds[] = ['id' => 're_part', 'payment_intent' => 'pi_adm_1', 'amount' => 1000, 'status' => 'succeeded'];

        $this->actingAsReauthed(admStaff())->postJson("/api/v1/admin/payments/{$p->id}/sync", ['reason' => 'Partial refund?'])->assertOk();

        expect($p->fresh())->status->toBe('paid')->needs_review->toBeTrue();
    });

    it('records a dispute, and a lost one becomes a chargeback with its credit note', function () {
        $p = admPurchase();
        app(InvoiceService::class)->issueForPurchase($p);
        $this->stripe->disputes[] = ['id' => 'dp_adm', 'payment_intent' => 'pi_adm_1', 'status' => 'needs_response'];

        $this->actingAsReauthed(admStaff())->postJson("/api/v1/admin/payments/{$p->id}/sync", ['reason' => 'Check dispute'])
            ->assertOk()->assertJsonPath('data.disputeStatus', 'needs_response')->assertJsonPath('data.needsReview', true);
        expect($p->fresh())->status->toBe('paid')->dispute_id->toBe('dp_adm')->disputed_at->not->toBeNull();

        $this->stripe->disputes[0]['status'] = 'lost';
        $this->actingAsReauthed(admStaff())->postJson("/api/v1/admin/payments/{$p->id}/sync", ['reason' => 'Check dispute'])->assertOk()->assertJsonPath('data.status', 'chargeback');

        expect($p->fresh())->status->toBe('chargeback')->chargeback_at->not->toBeNull()->and($p->event->fresh()->eventPlan->slug)->toBe('basic')
            ->and(CreditNote::where('purchase_id', $p->id)->count())->toBe(1);
    });

    it('clears the mark when the dispute was won', function () {
        $p = admPurchase();
        $this->stripe->disputes[] = ['id' => 'dp_won', 'payment_intent' => 'pi_adm_1', 'status' => 'won'];

        $this->actingAsReauthed(admStaff())->postJson("/api/v1/admin/payments/{$p->id}/sync", ['reason' => 'Check dispute'])->assertOk();

        expect($p->fresh())->status->toBe('paid')->dispute_status->toBe('won')->needs_review->toBeFalse();
    });

    it('is only for Stripe purchases, needs a reason, re-authentication and the permission, and 502s when Stripe fails', function () {
        $manual = admPurchase(['gateway' => 'manual', 'gateway_session_id' => null, 'gateway_payment_id' => null]);
        $stripePurchase = admPurchase();
        $staff = admStaff();

        $this->actingAsReauthed($staff)->postJson("/api/v1/admin/payments/{$manual->id}/sync", ['reason' => 'Check it'])->assertStatus(409)->assertJsonPath('code', 'not_stripe');
        $this->actingAsReauthed($staff)->postJson("/api/v1/admin/payments/{$stripePurchase->id}/sync", [])->assertStatus(422);
        $this->actingAs($staff)->postJson("/api/v1/admin/payments/{$stripePurchase->id}/sync", ['reason' => 'Check it'])->assertStatus(403);
        $this->actingAsReauthed(admStaff('support'))->postJson("/api/v1/admin/payments/{$stripePurchase->id}/sync", ['reason' => 'Check it'])->assertForbidden();

        $this->stripe->failNext = true;
        $this->actingAsReauthed($staff)->postJson("/api/v1/admin/payments/{$stripePurchase->id}/sync", ['reason' => 'Check it'])->assertStatus(502)->assertJsonPath('code', 'gateway_error');
        expect(AdminAuditLog::where('action', 'payment.sync')->count())->toBe(0);
    });
});

describe('resolve review', function () {
    it('clears the flag with a reason and an audit entry, and 409s when there is nothing to clear', function () {
        $p = admPurchase(['needs_review' => true]);
        $staff = admStaff();

        $this->actingAs($staff)->postJson("/api/v1/admin/payments/{$p->id}/resolve-review", [])->assertStatus(422);
        $this->actingAs($staff)->postJson("/api/v1/admin/payments/{$p->id}/resolve-review", ['reason' => 'Checked in Stripe'])->assertOk()->assertJsonPath('data.needsReview', false);
        $this->actingAs($staff)->postJson("/api/v1/admin/payments/{$p->id}/resolve-review", ['reason' => 'Checked in Stripe'])->assertStatus(409)->assertJsonPath('code', 'not_needs_review');
        $this->actingAs(admStaff('support'))->postJson("/api/v1/admin/payments/{$p->id}/resolve-review", ['reason' => 'Checked in Stripe'])->assertForbidden();

        expect($p->fresh()->needs_review)->toBeFalse()->and(AdminAuditLog::where('action', 'payment.review.resolve')->count())->toBe(1);
    });
});

describe('revenue view', function () {
    it('filters by chargeback, disputed and needsReview, and shows the new fields on each row', function () {
        admPurchase();
        $disputed = admPurchase(['dispute_id' => 'dp_1', 'dispute_status' => 'needs_response', 'needs_review' => true, 'gateway_payment_id' => 'pi_d']);
        $lost = admPurchase(['status' => 'chargeback', 'dispute_id' => 'dp_2', 'dispute_status' => 'lost', 'gateway_payment_id' => 'pi_l']);
        $staff = admStaff();

        $ids = fn (string $q) => collect($this->actingAs($staff)->getJson("/api/v1/admin/revenue/purchases?{$q}")->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

        expect($ids('status=chargeback'))->toBe([$lost->id])
            ->and($ids('disputed=1'))->toBe([$disputed->id, $lost->id])
            ->and($ids('needsReview=1'))->toBe([$disputed->id])
            ->and($ids('needsReview=0'))->toHaveCount(2);
        $row = collect($this->actingAs($staff)->getJson('/api/v1/admin/revenue/purchases?needsReview=1')->json('data'))->first();
        expect($row)->toMatchArray(['disputeStatus' => 'needs_response', 'needsReview' => true]);
        $this->actingAs($staff)->getJson('/api/v1/admin/revenue/purchases?status=bogus')->assertStatus(422);
    });

    it('shows the gateway data, a timeline with dispute and chargeback, and a Stripe link built on the server', function () {
        $p = admPurchase([
            'status' => 'chargeback', 'gateway_refund_id' => 're_9', 'failure_code' => null, 'dispute_id' => 'dp_9', 'dispute_status' => 'lost',
            'disputed_at' => now()->subDay(), 'chargeback_at' => now(), 'tax_cents' => 250, 'gateway_payment_id' => 'pi_3Abc123',
        ]);
        $staff = admStaff();

        $detail = $this->actingAs($staff)->getJson("/api/v1/admin/revenue/purchases/{$p->id}")->assertOk()->json('data');
        expect($detail)->toMatchArray([
            'gatewayPaymentId' => 'pi_3Abc123', 'gatewayRefundId' => 're_9', 'disputeId' => 'dp_9', 'disputeStatus' => 'lost', 'taxCents' => 250,
            'stripeUrl' => 'https://dashboard.stripe.com/test/payments/pi_3Abc123',
        ])->and(collect($detail['timeline'])->pluck('type')->all())->toBe(['created', 'paid', 'disputed', 'chargeback']);

        config(['services.stripe.secret' => 'rk_live_dummy']);
        $this->actingAs($staff)->getJson("/api/v1/admin/revenue/purchases/{$p->id}")->assertJsonPath('data.stripeUrl', 'https://dashboard.stripe.com/payments/pi_3Abc123');
    });

    it('never builds a link from anything but a Stripe payment id of a Stripe purchase', function () {
        $staff = admStaff();
        $weird = admPurchase(['gateway_payment_id' => 'pi_x/../../evil?next=https://evil.test']);
        $fake = admPurchase(['gateway' => 'fake', 'gateway_payment_id' => 'pi_ok']);
        $none = admPurchase(['gateway_payment_id' => null]);

        foreach ([$weird, $fake, $none] as $p) {
            $this->actingAs($staff)->getJson("/api/v1/admin/revenue/purchases/{$p->id}")->assertOk()->assertJsonPath('data.stripeUrl', null);
        }
    });
});

describe('tax on the invoice', function () {
    it('uses the tax Stripe calculated when the payment confirms, and the configured rate otherwise', function () {
        $p = admPurchase(['status' => 'pending', 'paid_at' => null, 'gateway_payment_id' => null], premium: false);
        $this->stripe->sessions[$p->gateway_session_id] = [
            'id' => $p->gateway_session_id, 'object' => 'checkout.session', 'status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_tax',
            'amount_total' => 9900, 'currency' => 'usd', 'url' => null, 'total_details' => ['amount_tax' => 700],
        ];

        $this->actingAsReauthed(admStaff())->postJson("/api/v1/admin/payments/{$p->id}/sync", ['reason' => 'Tax check'])->assertOk();

        $invoice = Invoice::where('purchase_id', $p->id)->first();
        expect($p->fresh()->tax_cents)->toBe(700)->and($invoice)->tax_cents->toBe(700)->total_cents->toBe(9900)
            ->and((float) $invoice->tax_rate)->toBe(7.61);

        $plain = admPurchase();
        app(InvoiceService::class)->issueForPurchase($plain);
        expect(Invoice::where('purchase_id', $plain->id)->first()->tax_cents)->toBe(0);
    });
});

it('reports no invoice on the status endpoint until one exists, with the failure code per purchase', function () {
    $owner = User::factory()->create();
    $p = admPurchase(['user_id' => $owner->id, 'status' => 'failed', 'failure_code' => 'expired', 'paid_at' => null]);
    EventUserRole::create(['event_id' => $p->event_id, 'user_id' => $owner->id, 'role_id' => Role::where('name', 'owner')->value('id')]);

    $this->actingAs($owner)->getJson("/api/v1/app/event/{$p->event_id}/purchase/status?purchase={$p->id}")
        ->assertOk()->assertJsonPath('data.invoiceId', null)->assertJsonPath('data.failureCode', 'expired')->assertJsonPath('data.status', 'failed');
});
