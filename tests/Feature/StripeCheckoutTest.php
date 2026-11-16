<?php

use App\Http\Services\Admin\AdminAudit;
use App\Http\Services\Payments\FakeGateway;
use App\Http\Services\Payments\PaymentGatewayException;
use App\Http\Services\Payments\PurchaseService;
use App\Http\Services\Payments\StripeGateway;
use App\Models\AdminAuditLog;
use App\Models\Coupon;
use App\Models\CreditNote;
use App\Models\EventPlan;
use App\Models\EventPurchase;
use App\Models\Events;
use App\Models\EventType;
use App\Models\EventUserRole;
use App\Models\Invoice;
use App\Models\PaymentEvent;
use App\Models\User;
use App\Support\ProductionSafetyCheck;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\LaunchCouponSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schedule;
use Spatie\Permission\Models\Role;
use Stripe\ApiRequestor;
use Tests\Support\FakeStripeHttp;

uses(RefreshDatabase::class);

const WHSEC = 'whsec_test_secret';

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    config([
        'services.payments.gateway' => 'stripe', 'services.stripe.secret' => 'sk_test_dummy', 'services.stripe.webhook_secret' => WHSEC,
        'services.stripe.automatic_tax' => false,
    ]);
    $this->stripe = new FakeStripeHttp;
    ApiRequestor::setHttpClient($this->stripe);
});

afterEach(fn () => ApiRequestor::setHttpClient(null));

function stripeEvent(string $role = 'owner'): array
{
    $user = User::factory()->create();
    $event = Events::factory()->draft()->create([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', 'basic')->value('id'),
    ]);
    EventUserRole::create(['event_id' => $event->id, 'user_id' => $user->id, 'role_id' => Role::where('name', $role)->value('id')]);

    return [$event, $user];
}

function startCheckout($test, Events $event, User $owner, ?string $code = null)
{
    $res = $test->actingAs($owner)->postJson("/api/v1/app/event/{$event->id}/purchase/checkout", $code ? ['code' => $code] : []);

    return $res;
}

/** A Stripe event body signed the way Stripe does it: t=<ts>,v1=HMAC-SHA256("<ts>.<body>"). */
function stripeWebhook($test, string $type, array $object, ?string $id = null, ?int $at = null, ?string $secret = WHSEC, ?string $header = null)
{
    $raw = json_encode([
        'id' => $id ?? 'evt_' . bin2hex(random_bytes(6)), 'object' => 'event', 'api_version' => '2026-10-28.endive',
        'created' => time(), 'livemode' => false, 'type' => $type, 'data' => ['object' => $object],
    ]);
    $ts = $at ?? time();
    $headers = ['CONTENT_TYPE' => 'application/json'];
    if ($header !== '') {
        $headers['HTTP_STRIPE_SIGNATURE'] = $header ?? "t={$ts},v1=" . hash_hmac('sha256', "{$ts}.{$raw}", (string) $secret);
    }

    return $test->call('POST', '/api/v1/app/payments/webhook', [], [], [], $headers, $raw);
}

function completedSession(EventPurchase $p, array $over = []): array
{
    return $over + [
        'id' => $p->gateway_session_id, 'object' => 'checkout.session', 'client_reference_id' => (string) $p->id,
        'metadata' => ['purchase_id' => (string) $p->id], 'payment_status' => 'paid', 'payment_intent' => 'pi_test_1',
        'amount_total' => $p->amount_cents, 'currency' => 'usd',
    ];
}

describe('session creation', function () {
    it('sends Stripe the final amount computed by the server, with metadata, expiry, locale and an idempotency key', function () {
        $this->seed(LaunchCouponSeeder::class);
        [$event, $owner] = stripeEvent();

        $res = $this->actingAs($owner)->withHeader('Accept-Language', 'en')
            ->postJson("/api/v1/app/event/{$event->id}/purchase/checkout", ['code' => 'lanzamiento79', 'amount' => 1, 'amount_cents' => 1])
            ->assertCreated();
        $purchase = EventPurchase::find($res->json('data.purchaseId'));

        expect($res->json('data.url'))->toStartWith('https://checkout.stripe.com/')
            ->and($purchase)->gateway->toBe('stripe')->amount_cents->toBe(7900)->status->toBe('pending')
            ->and($purchase->gateway_session_id)->toStartWith('cs_test_')->and($purchase->session_expires_at)->not->toBeNull();

        $call = $this->stripe->callsTo('post', '#^/v1/checkout/sessions$#')[0];
        $p = $call['params'];
        expect($p['mode'])->toBe('payment')
            ->and($p['line_items'][0]['price_data']['unit_amount'])->toBe(7900)
            ->and($p['line_items'][0]['price_data']['currency'])->toBe('usd')
            ->and($p['client_reference_id'])->toBe((string) $purchase->id)
            ->and($p['metadata'])->toMatchArray(['purchase_id' => (string) $purchase->id, 'event_id' => (string) $event->id, 'user_id' => (string) $owner->id, 'coupon' => 'LANZAMIENTO79', 'is_founder' => '1'])
            ->and($p['payment_intent_data']['metadata']['purchase_id'])->toBe((string) $purchase->id)
            ->and($p['locale'])->toBe('en')
            ->and($p['customer_email'])->toBe($owner->email)
            ->and($p['success_url'])->toContain('paid=1')->toContain("purchase={$purchase->id}")
            ->and($p['cancel_url'])->toContain('cancelled=1')->toContain("purchase={$purchase->id}")
            ->and($p['expires_at'])->toBe($purchase->created_at->copy()->addMinutes(60)->getTimestamp())
            ->and($p)->not->toHaveKey('automatic_tax')->not->toHaveKey('payment_method_types')
            ->and(implode('|', $call['headers']))->toContain("Idempotency-Key: checkout-{$purchase->id}");
    });

    it('quotes list price 9900 without a code and rejects exhausted codes', function () {
        $this->seed(LaunchCouponSeeder::class);
        [$event, $owner] = stripeEvent();

        startCheckout($this, $event, $owner)->assertCreated();
        expect($this->stripe->callsTo('post', '#^/v1/checkout/sessions$#')[0]['params']['line_items'][0]['price_data']['unit_amount'])->toBe(9900);

        Coupon::where('code', LaunchCouponSeeder::CODE)->update(['times_used' => 100]);
        startCheckout($this, $event, $owner, LaunchCouponSeeder::CODE)->assertStatus(422)->assertJsonPath('errors.code.0', 'invalid_code');
    });

    it('turns on automatic tax only by configuration', function () {
        config(['services.stripe.automatic_tax' => true]);
        [$event, $owner] = stripeEvent();

        startCheckout($this, $event, $owner)->assertCreated();

        $p = $this->stripe->callsTo('post', '#^/v1/checkout/sessions$#')[0]['params'];
        expect($p['automatic_tax'])->toBe(['enabled' => 'true'])->and($p['billing_address_collection'])->toBe('required')
            ->and($p['line_items'][0]['price_data']['tax_behavior'])->toBe('inclusive');
    });

    it('answers 502 gateway_error and leaves a failed purchase when Stripe fails', function () {
        [$event, $owner] = stripeEvent();
        $this->stripe->failNext = true;

        startCheckout($this, $event, $owner)->assertStatus(502)->assertJsonPath('code', 'gateway_error');

        expect(EventPurchase::first())->status->toBe('failed')->failure_code->toBe('gateway_error');
        startCheckout($this, $event, $owner)->assertCreated();
    });

    it('never calls Stripe for a free code', function () {
        Coupon::create(['code' => 'GIFT', 'kind' => 'percent_off', 'value' => 100]);
        [$event, $owner] = stripeEvent();

        startCheckout($this, $event, $owner, 'GIFT')->assertCreated();

        expect($this->stripe->calls)->toBe([])->and($event->fresh()->eventPlan->slug)->toBe('premium');
    });

    it('keeps one open session per event: same price reuses it, another price expires it', function () {
        Coupon::create(['code' => 'FUND79', 'kind' => 'fixed_price', 'value' => 7900]);
        [$event, $owner] = stripeEvent();

        $first = startCheckout($this, $event, $owner)->assertCreated();
        $again = startCheckout($this, $event, $owner)->assertCreated();
        expect($again->json('data.purchaseId'))->toBe($first->json('data.purchaseId'))->and($again->json('data.url'))->toBe($first->json('data.url'))
            ->and($this->stripe->callsTo('post', '#^/v1/checkout/sessions$#'))->toHaveCount(1);

        $other = startCheckout($this, $event, $owner, 'FUND79')->assertCreated();
        expect($other->json('data.purchaseId'))->not->toBe($first->json('data.purchaseId'))
            ->and(EventPurchase::find($first->json('data.purchaseId')))->status->toBe('failed')->failure_code->toBe('superseded')
            ->and($this->stripe->callsTo('post', '#/expire$#'))->toHaveCount(1)
            ->and(EventPurchase::where('status', 'pending')->count())->toBe(1);
    });
});

describe('gateway resolution', function () {
    it('never falls back to the fake gateway: stripe is stripe, an unknown name is an error', function () {
        $purchases = app(PurchaseService::class);

        expect($purchases->gateway())->toBeInstanceOf(StripeGateway::class)
            ->and($purchases->gateway('fake'))->toBeInstanceOf(FakeGateway::class);

        config(['services.payments.gateway' => 'strpe']);
        expect(fn () => $purchases->gateway())->toThrow(RuntimeException::class, 'Unknown payment gateway');

        config(['services.payments.gateway' => 'stripe', 'services.stripe.secret' => null]);
        expect(fn () => $purchases->gateway())->toThrow(RuntimeException::class, 'STRIPE_SECRET');
    });

    it('refuses a live key outside production', function () {
        config(['services.stripe.secret' => 'sk_live_dummy']);

        expect(fn () => app(PurchaseService::class)->gateway())->toThrow(RuntimeException::class, 'live Stripe key');
    });

    it('is checked by the production safety check', function () {
        $base = ['app.debug' => false, 'session.secure' => true, 'sanctum.stateful' => ['app.quinvite.com'], 'services.whatsapp.driver' => 'meta', 'services.whatsapp.app_secret' => 's', 'services.whatsapp.verify_token' => 'v'];

        config($base + ['services.stripe.secret' => 'sk_test_x', 'services.stripe.webhook_secret' => 'whsec_x']);
        expect(ProductionSafetyCheck::problems())->toContain('STRIPE_SECRET must be a live key (sk_live_ or rk_live_), not a test key.');

        config($base + ['services.stripe.secret' => null, 'services.stripe.webhook_secret' => null]);
        expect(ProductionSafetyCheck::problems())->toContain('STRIPE_SECRET must be set.', 'STRIPE_WEBHOOK_SECRET must be set.');

        config($base + ['services.payments.gateway' => 'strpe']);
        expect(ProductionSafetyCheck::problems())->toContain('PAYMENTS_GATEWAY must be "stripe" (or "manual").');

        config($base + ['services.stripe.secret' => 'rk_live_x', 'services.stripe.webhook_secret' => 'whsec_x', 'services.payments.gateway' => 'stripe']);
        expect(ProductionSafetyCheck::problems())->toBe([]);
    });
});

describe('webhook', function () {
    function pendingPurchase($test, ?string $code = null): array
    {
        [$event, $owner] = stripeEvent();
        $res = startCheckout($test, $event, $owner, $code)->assertCreated();

        return [$event, $owner, EventPurchase::find($res->json('data.purchaseId'))];
    }

    it('activates the plan once with a valid signature, and a repeated event does nothing', function () {
        Coupon::create(['code' => 'FUND79', 'kind' => 'fixed_price', 'value' => 7900, 'max_uses' => 5]);
        [$event, $owner, $purchase] = pendingPurchase($this, 'FUND79');

        stripeWebhook($this, 'checkout.session.completed', completedSession($purchase), 'evt_1')->assertOk();
        stripeWebhook($this, 'checkout.session.completed', completedSession($purchase), 'evt_1')->assertOk();
        // A different event id for the same payment (Stripe resent it under another id, or the async event): still once.
        stripeWebhook($this, 'checkout.session.async_payment_succeeded', completedSession($purchase), 'evt_2')->assertOk();

        expect($event->fresh()->eventPlan->slug)->toBe('premium')
            ->and($purchase->fresh())->status->toBe('paid')->gateway_payment_id->toBe('pi_test_1')
            ->and(Coupon::first()->times_used)->toBe(1)
            ->and(PaymentEvent::where('event_id', 'evt_1')->count())->toBe(1)
            ->and(PaymentEvent::where('event_id', 'evt_1')->first())->outcome->toBe('processed')->purchase_id->toBe($purchase->id)
            ->and(Invoice::where('purchase_id', $purchase->id)->count())->toBe(1);
    });

    it('rejects a missing, forged, wrongly keyed or stale signature with 400 and processes nothing', function () {
        [$event, $owner, $purchase] = pendingPurchase($this);
        $session = completedSession($purchase);

        stripeWebhook($this, 'checkout.session.completed', $session, header: '')->assertStatus(400);
        stripeWebhook($this, 'checkout.session.completed', $session, header: 't=1,v1=deadbeef')->assertStatus(400);
        stripeWebhook($this, 'checkout.session.completed', $session, secret: 'whsec_other')->assertStatus(400);
        stripeWebhook($this, 'checkout.session.completed', $session, at: time() - 3600)->assertStatus(400);

        expect($purchase->fresh()->status)->toBe('pending')->and(PaymentEvent::count())->toBe(0);
    });

    it('verifies the raw body: a re-encoded body does not pass', function () {
        [$event, $owner, $purchase] = pendingPurchase($this);
        $raw = json_encode(['id' => 'evt_raw', 'object' => 'event', 'type' => 'checkout.session.completed', 'data' => ['object' => completedSession($purchase)]]);
        $ts = time();
        $sig = "t={$ts},v1=" . hash_hmac('sha256', "{$ts}.{$raw}", WHSEC);
        $pretty = json_encode(json_decode($raw, true), JSON_PRETTY_PRINT);

        $this->call('POST', '/api/v1/app/payments/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $sig], $pretty)->assertStatus(400);
        $this->call('POST', '/api/v1/app/payments/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $sig], $raw)->assertOk();
        expect($purchase->fresh()->status)->toBe('paid');
    });

    it('answers 503 when there is no webhook secret, and the fake secret signs nothing under stripe', function () {
        [$event, $owner, $purchase] = pendingPurchase($this);
        $session = completedSession($purchase);

        // The local fake signature (X-Signature) is not accepted while the gateway is Stripe.
        $raw = json_encode(['type' => 'payment.succeeded', 'session' => $purchase->gateway_session_id]);
        $this->call('POST', '/api/v1/app/payments/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGNATURE' => FakeGateway::sign($raw)], $raw)->assertStatus(400);

        config(['services.stripe.webhook_secret' => null]);
        stripeWebhook($this, 'checkout.session.completed', $session)->assertStatus(503);
        expect($purchase->fresh()->status)->toBe('pending');
    });

    it('answers 200 and flags the purchase when the amount or currency do not match, activating nothing', function () {
        [$event, $owner, $purchase] = pendingPurchase($this);

        stripeWebhook($this, 'checkout.session.completed', completedSession($purchase, ['amount_total' => 100]), 'evt_amt')->assertOk();
        stripeWebhook($this, 'checkout.session.completed', completedSession($purchase, ['currency' => 'eur']), 'evt_cur')->assertOk();

        expect($purchase->fresh())->status->toBe('pending')->needs_review->toBeTrue()->failure_code->toBe('amount_mismatch')
            ->and($event->fresh()->eventPlan->slug)->toBe('basic')
            ->and(PaymentEvent::where('event_id', 'evt_amt')->value('outcome'))->toBe('mismatch');
    });

    it('flags a reference that is not the purchase it found', function () {
        [$event, $owner, $purchase] = pendingPurchase($this);

        stripeWebhook($this, 'checkout.session.completed', completedSession($purchase, ['client_reference_id' => '99999']))->assertOk();

        expect($purchase->fresh())->status->toBe('pending')->needs_review->toBeTrue();
    });

    it('answers 200 for an unknown purchase and for events it does not use', function () {
        stripeWebhook($this, 'checkout.session.completed', ['id' => 'cs_unknown', 'object' => 'checkout.session', 'client_reference_id' => '424242', 'payment_status' => 'paid', 'amount_total' => 100, 'currency' => 'usd'], 'evt_unk')->assertOk();
        stripeWebhook($this, 'payment_intent.payment_failed', ['id' => 'pi_x', 'object' => 'payment_intent'], 'evt_ign')->assertOk();

        expect(PaymentEvent::pluck('outcome', 'event_id')->all())->toBe(['evt_unk' => 'ignored', 'evt_ign' => 'ignored']);
    });

    it('does nothing for a session that completed unpaid (delayed method) until it succeeds', function () {
        [$event, $owner, $purchase] = pendingPurchase($this);

        stripeWebhook($this, 'checkout.session.completed', completedSession($purchase, ['payment_status' => 'unpaid']))->assertOk();
        expect($purchase->fresh()->status)->toBe('pending');

        stripeWebhook($this, 'checkout.session.async_payment_succeeded', completedSession($purchase))->assertOk();
        expect($purchase->fresh()->status)->toBe('paid');
    });

    it('fails the purchase on expiry or async failure and releases the coupon use', function () {
        Coupon::create(['code' => 'ONE', 'kind' => 'fixed_price', 'value' => 5000, 'max_uses' => 1]);
        [$event, $owner, $purchase] = pendingPurchase($this, 'ONE');
        [$eventB, $ownerB] = stripeEvent();
        startCheckout($this, $eventB, $ownerB, 'ONE')->assertStatus(422);

        stripeWebhook($this, 'checkout.session.expired', ['id' => $purchase->gateway_session_id, 'object' => 'checkout.session', 'client_reference_id' => (string) $purchase->id])->assertOk();

        expect($purchase->fresh())->status->toBe('failed')->failure_code->toBe('expired');
        startCheckout($this, $eventB, $ownerB, 'ONE')->assertCreated();

        [, , $second] = pendingPurchase($this);
        stripeWebhook($this, 'checkout.session.async_payment_failed', completedSession($second, ['payment_status' => 'unpaid']))->assertOk();
        expect($second->fresh())->status->toBe('failed')->failure_code->toBe('payment_failed');
    });

    it('never goes backwards: an old expiry or failure does not undo a payment, and a refund is not paid again', function () {
        [$event, $owner, $purchase] = pendingPurchase($this);
        stripeWebhook($this, 'checkout.session.completed', completedSession($purchase))->assertOk();

        stripeWebhook($this, 'checkout.session.expired', completedSession($purchase))->assertOk();
        stripeWebhook($this, 'checkout.session.async_payment_failed', completedSession($purchase))->assertOk();
        expect($purchase->fresh()->status)->toBe('paid')->and($event->fresh()->eventPlan->slug)->toBe('premium');

        app(PurchaseService::class)->refund($purchase, 'customer asked');
        stripeWebhook($this, 'checkout.session.completed', completedSession($purchase))->assertOk();
        expect($purchase->fresh()->status)->toBe('refunded')->and($event->fresh()->eventPlan->slug)->toBe('basic');
    });

    it('lets a late payment on an expired session activate the plan (failed -> paid)', function () {
        [$event, $owner, $purchase] = pendingPurchase($this);
        stripeWebhook($this, 'checkout.session.expired', completedSession($purchase))->assertOk();
        expect($purchase->fresh()->status)->toBe('failed');

        stripeWebhook($this, 'checkout.session.completed', completedSession($purchase))->assertOk();

        expect($purchase->fresh())->status->toBe('paid')->failure_code->toBeNull()->and($event->fresh()->eventPlan->slug)->toBe('premium');
    });

    it('refunds a second payment for an event that is already paid, and flags it', function () {
        [$event, $owner, $first] = pendingPurchase($this);
        Coupon::create(['code' => 'FUND79', 'kind' => 'fixed_price', 'value' => 7900]);
        $second = EventPurchase::create([
            'event_id' => $event->id, 'user_id' => $owner->id, 'event_plan_id' => $first->event_plan_id, 'gateway' => 'stripe',
            'list_price_cents' => 9900, 'amount_cents' => 9900, 'currency' => 'usd', 'status' => 'pending', 'gateway_session_id' => 'cs_test_dup',
        ]);
        stripeWebhook($this, 'checkout.session.completed', completedSession($first))->assertOk();

        stripeWebhook($this, 'checkout.session.completed', completedSession($second, ['payment_intent' => 'pi_dup']))->assertOk();

        expect($second->fresh())->status->toBe('refunded')->needs_review->toBeTrue()->gateway_refund_id->toStartWith('re_test_')
            ->and($first->fresh()->status)->toBe('paid')
            ->and(Invoice::count())->toBe(1);
        $refund = $this->stripe->callsTo('post', '#^/v1/refunds$#')[0];
        expect($refund['params']['payment_intent'])->toBe('pi_dup')->and(implode('|', $refund['headers']))->toContain("Idempotency-Key: refund-{$second->id}");
    });

    it('records a refund made in the Stripe dashboard, and flags it when the event is published', function () {
        [$event, $owner, $purchase] = pendingPurchase($this);
        stripeWebhook($this, 'checkout.session.completed', completedSession($purchase))->assertOk();

        stripeWebhook($this, 'charge.refunded', ['id' => 'ch_1', 'object' => 'charge', 'payment_intent' => 'pi_test_1', 'amount' => 9900, 'amount_refunded' => 9900, 'currency' => 'usd'])->assertOk();

        expect($purchase->fresh())->status->toBe('refunded')->and($event->fresh()->eventPlan->slug)->toBe('basic')
            ->and(CreditNote::where('purchase_id', $purchase->id)->count())->toBe(1);

        [$event2, , $p2] = pendingPurchase($this);
        $event2->update(['status' => 'published']);
        stripeWebhook($this, 'checkout.session.completed', completedSession($p2, ['payment_intent' => 'pi_pub']))->assertOk();
        stripeWebhook($this, 'charge.refunded', ['id' => 'ch_2', 'object' => 'charge', 'payment_intent' => 'pi_pub', 'amount' => 9900, 'amount_refunded' => 9900])->assertOk();

        expect($p2->fresh())->status->toBe('refunded')->needs_review->toBeTrue()->and($event2->fresh()->eventPlan->slug)->toBe('premium');
    });

    it('marks a dispute without touching the plan, clears it when won and reverses the purchase when lost', function () {
        [$event, $owner, $purchase] = pendingPurchase($this);
        stripeWebhook($this, 'checkout.session.completed', completedSession($purchase))->assertOk();

        stripeWebhook($this, 'charge.dispute.created', ['id' => 'dp_1', 'object' => 'dispute', 'payment_intent' => 'pi_test_1', 'status' => 'needs_response'])->assertOk();
        expect($purchase->fresh())->status->toBe('paid')->dispute_id->toBe('dp_1')->dispute_status->toBe('needs_response')->needs_review->toBeTrue()
            ->and($event->fresh()->eventPlan->slug)->toBe('premium');

        stripeWebhook($this, 'charge.dispute.closed', ['id' => 'dp_1', 'object' => 'dispute', 'payment_intent' => 'pi_test_1', 'status' => 'won'])->assertOk();
        expect($purchase->fresh())->status->toBe('paid')->dispute_status->toBe('won')->needs_review->toBeFalse();

        stripeWebhook($this, 'charge.dispute.closed', ['id' => 'dp_1', 'object' => 'dispute', 'payment_intent' => 'pi_test_1', 'status' => 'lost'])->assertOk();
        expect($purchase->fresh())->status->toBe('chargeback')->and($event->fresh()->eventPlan->slug)->toBe('basic');
    });
});

describe('status, reconciliation and cancel', function () {
    it('reconciles a pending purchase with Stripe once per 15 seconds and unlocks when the session is paid', function () {
        [$event, $owner] = stripeEvent();
        $res = startCheckout($this, $event, $owner)->assertCreated();
        $purchase = EventPurchase::find($res->json('data.purchaseId'));
        $url = "/api/v1/app/event/{$event->id}/purchase/status?purchase={$purchase->id}";

        // Too young: no call to Stripe.
        $this->actingAs($owner)->getJson($url)->assertJsonPath('data.status', 'pending')->assertJsonPath('data.unlocked', false);
        expect($this->stripe->callsTo('get', '#^/v1/checkout/sessions/#'))->toHaveCount(0);

        $this->travel(30)->seconds();
        $this->actingAs($owner)->getJson($url)->assertJsonPath('data.status', 'pending');
        $this->actingAs($owner)->getJson($url)->assertJsonPath('data.status', 'pending');
        expect($this->stripe->callsTo('get', '#^/v1/checkout/sessions/#'))->toHaveCount(1);

        $this->stripe->pay($purchase->gateway_session_id);
        $this->travel(16)->seconds();
        $this->actingAs($owner)->getJson($url)
            ->assertJsonPath('data.status', 'paid')->assertJsonPath('data.unlocked', true)->assertJsonPath('data.purchaseId', $purchase->id)
            ->assertJsonPath('data.amountCents', 9900)->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.invoiceId', Invoice::where('purchase_id', $purchase->id)->value('id'));
    });

    it('marks an expired session failed when reconciling', function () {
        [$event, $owner] = stripeEvent();
        $purchase = EventPurchase::find(startCheckout($this, $event, $owner)->json('data.purchaseId'));
        $this->stripe->sessions[$purchase->gateway_session_id]['status'] = 'expired';
        $this->travel(30)->seconds();

        $this->actingAs($owner)->getJson("/api/v1/app/event/{$event->id}/purchase/status")
            ->assertJsonPath('data.status', 'failed')->assertJsonPath('data.failureCode', 'expired');
    });

    it('does not let a purchase of another event be read (IDOR)', function () {
        [$event, $owner] = stripeEvent();
        [$otherEvent, $otherOwner] = stripeEvent();
        $other = EventPurchase::find(startCheckout($this, $otherEvent, $otherOwner)->json('data.purchaseId'));

        $this->actingAs($owner)->getJson("/api/v1/app/event/{$event->id}/purchase/status?purchase={$other->id}")->assertNotFound();
        $this->actingAs($owner)->postJson("/api/v1/app/event/{$event->id}/purchase/{$other->id}/cancel")->assertNotFound();
        expect($other->fresh()->status)->toBe('pending');
    });

    it('cancels a pending purchase: expires the session, frees the coupon, and 409s the second time', function () {
        Coupon::create(['code' => 'ONE', 'kind' => 'fixed_price', 'value' => 5000, 'max_uses' => 1]);
        [$event, $owner] = stripeEvent();
        $purchase = EventPurchase::find(startCheckout($this, $event, $owner, 'ONE')->json('data.purchaseId'));

        $this->actingAs($owner)->postJson("/api/v1/app/event/{$event->id}/purchase/{$purchase->id}/cancel")
            ->assertOk()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.failureCode', 'cancelled');

        expect($this->stripe->callsTo('post', '#/expire$#'))->toHaveCount(1)->and($this->stripe->sessions[$purchase->gateway_session_id]['status'])->toBe('expired');
        $this->actingAs($owner)->postJson("/api/v1/app/event/{$event->id}/purchase/{$purchase->id}/cancel")->assertStatus(409)->assertJsonPath('code', 'not_pending');
        [$eventB, $ownerB] = stripeEvent();
        startCheckout($this, $eventB, $ownerB, 'ONE')->assertCreated();
    });

    it('does not cancel a purchase that was paid meanwhile', function () {
        [$event, $owner] = stripeEvent();
        $purchase = EventPurchase::find(startCheckout($this, $event, $owner)->json('data.purchaseId'));
        $this->stripe->pay($purchase->gateway_session_id);

        $this->actingAs($owner)->postJson("/api/v1/app/event/{$event->id}/purchase/{$purchase->id}/cancel")->assertStatus(409);

        expect($purchase->fresh()->status)->toBe('paid')->and($event->fresh()->eventPlan->slug)->toBe('premium');
    });

    it('is only for the owner', function () {
        [$event, $editor] = stripeEvent('editor');

        $this->actingAs($editor)->postJson("/api/v1/app/event/{$event->id}/purchase/1/cancel")->assertForbidden();
    });

    it('payments:reconcile settles purchases pending between 10 minutes and 24 hours', function () {
        [$eventA, $ownerA] = stripeEvent();
        [$eventB, $ownerB] = stripeEvent();
        [$eventC, $ownerC] = stripeEvent();
        $a = EventPurchase::find(startCheckout($this, $eventA, $ownerA)->json('data.purchaseId'));
        $b = EventPurchase::find(startCheckout($this, $eventB, $ownerB)->json('data.purchaseId'));
        $c = EventPurchase::find(startCheckout($this, $eventC, $ownerC)->json('data.purchaseId'));
        foreach ([$a, $b] as $p) {
            $this->stripe->pay($p->gateway_session_id, 'pi_' . $p->id);
        }
        $a->forceFill(['created_at' => now()->subMinutes(15)])->save();
        $b->forceFill(['created_at' => now()->subMinutes(2)])->save(); // too young
        $c->forceFill(['created_at' => now()->subMinutes(15)])->save(); // not paid in Stripe

        $this->artisan('payments:reconcile')->assertSuccessful();

        expect($a->fresh()->status)->toBe('paid')->and($b->fresh()->status)->toBe('pending')->and($c->fresh()->status)->toBe('pending');
        expect(Schedule::events())->not->toBeEmpty();
    });
});

describe('refund through Stripe', function () {
    it('asks Stripe for the refund with an idempotency key and stores its id', function () {
        [$event, $owner] = stripeEvent();
        $purchase = EventPurchase::find(startCheckout($this, $event, $owner)->json('data.purchaseId'));
        stripeWebhook($this, 'checkout.session.completed', completedSession($purchase))->assertOk();

        $refunded = app(PurchaseService::class)->refund($purchase, 'duplicate');

        expect($refunded)->status->toBe('refunded')->gateway_refund_id->toStartWith('re_test_')->refund_reason->toBe('duplicate');
        $call = $this->stripe->callsTo('post', '#^/v1/refunds$#')[0];
        expect($call['params']['payment_intent'])->toBe('pi_test_1')->and(implode('|', $call['headers']))->toContain("Idempotency-Key: refund-{$purchase->id}");
    });

    it('keeps the purchase paid when Stripe refuses the refund', function () {
        [$event, $owner] = stripeEvent();
        $purchase = EventPurchase::find(startCheckout($this, $event, $owner)->json('data.purchaseId'));
        stripeWebhook($this, 'checkout.session.completed', completedSession($purchase))->assertOk();
        $this->stripe->failNext = true;

        expect(fn () => app(PurchaseService::class)->refund($purchase, 'x'))->toThrow(PaymentGatewayException::class);
        expect($purchase->fresh()->status)->toBe('paid')->and($event->fresh()->eventPlan->slug)->toBe('premium');
    });
});

it('can write the audit log with no actor (system events)', function () {
    [$event] = stripeEvent();
    $purchase = EventPurchase::create([
        'event_id' => $event->id, 'user_id' => User::factory()->create()->id, 'event_plan_id' => $event->event_plan_id, 'gateway' => 'stripe',
        'list_price_cents' => 9900, 'amount_cents' => 9900, 'currency' => 'usd', 'status' => 'pending',
    ]);

    $row = app(AdminAudit::class)->record('payment.system', $purchase, ['after' => ['status' => 'paid']]);

    expect(AdminAuditLog::find($row->id))->actor_id->toBeNull()->action->toBe('payment.system');
});
