<?php

use App\Http\Services\Payments\PurchaseService;
use App\Models\AdminAuditLog;
use App\Models\Coupon;
use App\Models\EventPlan;
use App\Models\EventPurchase;
use App\Models\Events;
use App\Models\EventType;
use App\Models\EventUserRole;
use App\Models\Invoice;
use App\Models\LaunchOffer;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Demo\DemoSeeder;
use Database\Seeders\LaunchCouponSeeder;
use Database\Seeders\LaunchOfferSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Stripe\ApiRequestor;
use Tests\Support\FakeStripeHttp;

uses(RefreshDatabase::class);

const OFFER_WHSEC = 'whsec_test_secret';

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    config([
        'services.payments.gateway' => 'stripe', 'services.stripe.secret' => 'sk_test_dummy', 'services.stripe.webhook_secret' => OFFER_WHSEC,
        'services.stripe.automatic_tax' => false,
    ]);
    $this->stripe = new FakeStripeHttp;
    ApiRequestor::setHttpClient($this->stripe);
});

afterEach(fn () => ApiRequestor::setHttpClient(null));

function offerEvent(): array
{
    $user = User::factory()->create();
    $event = Events::factory()->draft()->create([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', 'basic')->value('id'),
    ]);
    EventUserRole::create(['event_id' => $event->id, 'user_id' => $user->id, 'role_id' => Role::where('name', 'owner')->value('id')]);

    return [$event, $user];
}

function makeOffer(array $over = []): LaunchOffer
{
    $offer = LaunchOffer::create($over + [
        'event_plan_id' => EventPlan::where('slug', 'premium')->value('id'), 'is_active' => true, 'price_cents' => 7900, 'total_slots' => 100,
    ]);
    // The founder code, when it exists, shares the offer's slots (what the seeders do).
    Coupon::where('code', 'LANZAMIENTO79')->update(['launch_offer_id' => $offer->id]);

    return $offer;
}

function quoteOf($test, Events $event, User $owner, ?string $code = null)
{
    return $test->actingAs($owner)->getJson("/api/v1/app/event/{$event->id}/purchase/quote" . ($code ? "?code={$code}" : ''));
}

function checkoutOf($test, Events $event, User $owner, array $body = [])
{
    return $test->actingAs($owner)->postJson("/api/v1/app/event/{$event->id}/purchase/checkout", $body);
}

function payPurchase($test, int $purchaseId, ?string $eventId = null): void
{
    $p = EventPurchase::find($purchaseId);
    $raw = json_encode([
        'id' => $eventId ?? 'evt_' . bin2hex(random_bytes(6)), 'object' => 'event', 'api_version' => '2026-10-28.endive', 'created' => time(), 'livemode' => false,
        'type' => 'checkout.session.completed', 'data' => ['object' => [
            'id' => $p->gateway_session_id, 'object' => 'checkout.session', 'client_reference_id' => (string) $p->id,
            'metadata' => ['purchase_id' => (string) $p->id], 'payment_status' => 'paid', 'payment_intent' => 'pi_' . $p->id,
            'amount_total' => $p->amount_cents, 'currency' => 'usd',
        ]],
    ]);
    $ts = time();
    $test->call('POST', '/api/v1/app/payments/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => "t={$ts},v1=" . hash_hmac('sha256', "{$ts}.{$raw}", OFFER_WHSEC),
    ], $raw)->assertOk();
}

function lastStripeAmount($test): int
{
    $calls = $test->stripe->callsTo('post', '#^/v1/checkout/sessions$#');

    return $calls[array_key_last($calls)]['params']['line_items'][0]['price_data']['unit_amount'];
}

describe('quote', function () {
    it('shows 79 with the slots left while the offer is open, and 99 as list price', function () {
        makeOffer(['total_slots' => 3]);
        [$event, $owner] = offerEvent();

        quoteOf($this, $event, $owner)->assertOk()
            ->assertJsonPath('data.amountCents', 7900)->assertJsonPath('data.listPriceCents', 9900)->assertJsonPath('data.source', 'offer')
            ->assertJsonPath('data.code', null)
            ->assertJsonPath('data.offer', ['active' => true, 'priceCents' => 7900, 'listPriceCents' => 9900, 'slotsLeft' => 3, 'totalSlots' => 3]);
    });

    it('goes back to 99 when the slots run out', function () {
        $offer = makeOffer(['total_slots' => 1]);
        [$event, $owner] = offerEvent();
        [$other, $otherOwner] = offerEvent();
        $buyer = checkoutOf($this, $other, $otherOwner)->assertCreated()->json('data.purchaseId');
        payPurchase($this, $buyer);

        quoteOf($this, $event, $owner)->assertOk()
            ->assertJsonPath('data.amountCents', 9900)->assertJsonPath('data.source', 'list')
            ->assertJsonPath('data.offer.active', false)->assertJsonPath('data.offer.slotsLeft', 0)->assertJsonPath('data.offer.totalSlots', 1);
        expect($offer->usedSlots())->toBe(1);
    });

    it('is 99 when the offer is switched off or does not exist, and not below the list price when the offer price is not lower', function () {
        [$event, $owner] = offerEvent();
        quoteOf($this, $event, $owner)->assertOk()->assertJsonPath('data.amountCents', 9900)
            ->assertJsonPath('data.offer', ['active' => false, 'priceCents' => null, 'listPriceCents' => 9900, 'slotsLeft' => 0, 'totalSlots' => 0]);

        $offer = makeOffer(['is_active' => false]);
        quoteOf($this, $event, $owner)->assertJsonPath('data.amountCents', 9900)->assertJsonPath('data.offer.active', false)->assertJsonPath('data.offer.slotsLeft', 0);

        $offer->update(['is_active' => true, 'price_cents' => 9900]);
        quoteOf($this, $event, $owner)->assertJsonPath('data.amountCents', 9900)->assertJsonPath('data.offer.active', false);
    });

    it('does not change the price of an event that already paid, and the status endpoint carries the offer', function () {
        $offer = makeOffer();
        [$event, $owner] = offerEvent();
        $id = checkoutOf($this, $event, $owner)->assertCreated()->json('data.purchaseId');
        payPurchase($this, $id);

        $offer->update(['price_cents' => 5000]);
        expect(EventPurchase::find($id))->amount_cents->toBe(7900)->status->toBe('paid');
        quoteOf($this, $event, $owner)->assertOk()->assertJsonPath('data.unlocked', true);
        $this->actingAs($owner)->getJson("/api/v1/app/event/{$event->id}/purchase/status")
            ->assertOk()->assertJsonPath('data.amountCents', 7900)->assertJsonPath('data.offer.priceCents', 5000)->assertJsonPath('data.offer.slotsLeft', 99);
    });

    it('never leaks other events: only the public counts are returned', function () {
        makeOffer(['total_slots' => 5]);
        [$event, $owner] = offerEvent();
        [$other, $otherOwner] = offerEvent();
        checkoutOf($this, $other, $otherOwner)->assertCreated();

        $json = quoteOf($this, $event, $owner)->assertOk()->json('data');

        expect($json['offer']['slotsLeft'])->toBe(4)->and(json_encode($json))->not->toContain((string) $other->event_name);
        $this->actingAs($owner)->getJson("/api/v1/app/event/{$other->id}/purchase/quote")->assertNotFound();
    });
});

describe('checkout and money', function () {
    it('charges 79 in Stripe, stores the offer, settles with the matching amount and invoices 79', function () {
        $offer = makeOffer();
        [$event, $owner] = offerEvent();

        $id = checkoutOf($this, $event, $owner, ['expectedAmountCents' => 7900])->assertCreated()->json('data.purchaseId');
        $purchase = EventPurchase::find($id);
        expect(lastStripeAmount($this))->toBe(7900)
            ->and($purchase)->amount_cents->toBe(7900)->list_price_cents->toBe(9900)->launch_offer_id->toBe($offer->id)->coupon_id->toBeNull()
            ->and($offer->usedSlots())->toBe(0); // pending: held, not consumed

        payPurchase($this, $id);

        $invoice = Invoice::where('purchase_id', $id)->first();
        expect($purchase->fresh())->status->toBe('paid')->and($event->fresh()->eventPlan->slug)->toBe('premium')
            ->and($offer->usedSlots())->toBe(1)
            ->and($invoice)->total_cents->toBe(7900)->discount_cents->toBe(2000);
    });

    it('flags an amount that is not the offer price and activates nothing (amount_mismatch keeps working)', function () {
        makeOffer();
        [$event, $owner] = offerEvent();
        $id = checkoutOf($this, $event, $owner)->assertCreated()->json('data.purchaseId');
        EventPurchase::whereKey($id)->update(['amount_cents' => 7900]);
        $p = EventPurchase::find($id);

        $raw = json_encode(['id' => 'evt_bad', 'object' => 'event', 'api_version' => '2026-10-28.endive', 'created' => time(), 'livemode' => false, 'type' => 'checkout.session.completed',
            'data' => ['object' => ['id' => $p->gateway_session_id, 'object' => 'checkout.session', 'client_reference_id' => (string) $p->id, 'metadata' => ['purchase_id' => (string) $p->id],
                'payment_status' => 'paid', 'payment_intent' => 'pi_x', 'amount_total' => 9900, 'currency' => 'usd']]]);
        $ts = time();
        $this->call('POST', '/api/v1/app/payments/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => "t={$ts},v1=" . hash_hmac('sha256', "{$ts}.{$raw}", OFFER_WHSEC)], $raw)->assertOk();

        expect($p->fresh())->status->toBe('pending')->needs_review->toBeTrue()->failure_code->toBe('amount_mismatch')
            ->and(LaunchOffer::first()->usedSlots())->toBe(0);
    });

    it('consumes the slot once even when the webhook is delivered twice', function () {
        $offer = makeOffer(['total_slots' => 2]);
        [$event, $owner] = offerEvent();
        $id = checkoutOf($this, $event, $owner)->assertCreated()->json('data.purchaseId');

        payPurchase($this, $id, 'evt_same');
        payPurchase($this, $id, 'evt_same');
        payPurchase($this, $id, 'evt_other_delivery');

        expect($offer->usedSlots())->toBe(1)->and($offer->slotsLeft())->toBe(1)->and(Invoice::where('purchase_id', $id)->count())->toBe(1);
    });

    it('frees the slot when the purchase is refunded, and when a pending one fails or is cancelled', function () {
        $offer = makeOffer(['total_slots' => 1]);
        [$event, $owner] = offerEvent();
        [$second, $secondOwner] = offerEvent();
        $id = checkoutOf($this, $event, $owner)->assertCreated()->json('data.purchaseId');
        payPurchase($this, $id);
        expect($offer->slotsLeft())->toBe(0);
        quoteOf($this, $second, $secondOwner)->assertJsonPath('data.amountCents', 9900);

        app(PurchaseService::class)->refund(EventPurchase::find($id), 'asked');

        expect($offer->usedSlots())->toBe(0)->and($offer->slotsLeft())->toBe(1);
        quoteOf($this, $second, $secondOwner)->assertJsonPath('data.amountCents', 7900)->assertJsonPath('data.offer.slotsLeft', 1);

        // A pending purchase holds the slot against other events, then lets it go when cancelled.
        $pending = checkoutOf($this, $second, $secondOwner)->assertCreated()->json('data.purchaseId');
        expect($offer->slotsLeft())->toBe(0);
        $this->actingAs($secondOwner)->postJson("/api/v1/app/event/{$second->id}/purchase/{$pending}/cancel")->assertOk();
        expect($offer->slotsLeft())->toBe(1);
    });

    it('lets a pending hold expire after an hour', function () {
        $offer = makeOffer(['total_slots' => 1]);
        [$event, $owner] = offerEvent();
        [$other, $otherOwner] = offerEvent();
        checkoutOf($this, $event, $owner)->assertCreated();
        quoteOf($this, $other, $otherOwner)->assertJsonPath('data.amountCents', 9900);

        $this->travel(61)->minutes();

        quoteOf($this, $other, $otherOwner)->assertJsonPath('data.amountCents', 7900);
        expect($offer->slotsLeft())->toBe(1);
    });

    it('keeps the buyer\'s own pending checkout reusable: it holds nothing against her own event', function () {
        makeOffer(['total_slots' => 1]);
        [$event, $owner] = offerEvent();
        $first = checkoutOf($this, $event, $owner)->assertCreated()->json('data.purchaseId');

        quoteOf($this, $event, $owner)->assertJsonPath('data.amountCents', 7900)->assertJsonPath('data.offer.slotsLeft', 1);
        $second = checkoutOf($this, $event, $owner)->assertCreated()->json('data.purchaseId');

        expect($second)->toBe($first)->and(EventPurchase::count())->toBe(1);
    });

    it('serves the last slot to one of two checkouts and tells the other the price changed (race)', function () {
        $offer = makeOffer(['total_slots' => 1]);
        [$a, $ownerA] = offerEvent();
        [$b, $ownerB] = offerEvent();

        // Both saw 79 with one slot left.
        quoteOf($this, $a, $ownerA)->assertJsonPath('data.amountCents', 7900);
        quoteOf($this, $b, $ownerB)->assertJsonPath('data.amountCents', 7900);

        checkoutOf($this, $a, $ownerA, ['expectedAmountCents' => 7900])->assertCreated();
        checkoutOf($this, $b, $ownerB, ['expectedAmountCents' => 7900])->assertStatus(409)
            ->assertJsonPath('code', 'price_changed')->assertJsonPath('data.amountCents', 9900)->assertJsonPath('data.offer.slotsLeft', 0);

        expect(EventPurchase::where('event_id', $b->id)->count())->toBe(0)
            ->and(EventPurchase::where('launch_offer_id', $offer->id)->count())->toBe(1);

        // Without an expected amount the buyer simply pays what Stripe shows: the list price, and no offer id.
        $id = checkoutOf($this, $b, $ownerB)->assertCreated()->json('data.purchaseId');
        expect(EventPurchase::find($id))->amount_cents->toBe(9900)->launch_offer_id->toBeNull()->and(lastStripeAmount($this))->toBe(9900);
    });

    it('honours the price already charged when payments exceed the slots, flagging the over-cap purchase', function () {
        $offer = makeOffer(['total_slots' => 1]);
        [$a, $ownerA] = offerEvent();
        [$b, $ownerB] = offerEvent();
        $first = checkoutOf($this, $a, $ownerA)->assertCreated()->json('data.purchaseId');

        // The hold of the first one expired while she was still paying; the second took the slot at checkout.
        $this->travel(61)->minutes();
        $second = checkoutOf($this, $b, $ownerB)->assertCreated()->json('data.purchaseId');
        expect(EventPurchase::find($second)->launch_offer_id)->toBe($offer->id);

        payPurchase($this, $second);
        payPurchase($this, $first);

        $firstPurchase = EventPurchase::find($first);
        $secondPurchase = EventPurchase::find($second);
        expect($firstPurchase)->status->toBe('paid')->amount_cents->toBe(7900)->needs_review->toBeTrue()
            ->and($firstPurchase->notes)->toContain('launch_offer_oversold')
            ->and($secondPurchase)->status->toBe('paid')->needs_review->toBeFalse()
            ->and($a->fresh()->eventPlan->slug)->toBe('premium')->and($b->fresh()->eventPlan->slug)->toBe('premium')
            ->and($offer->usedSlots())->toBe(2)->and($offer->slotsLeft())->toBe(0);
    });

    it('does not flag a purchase that was refunded or is not on the offer when the commit hook runs', function () {
        $offer = makeOffer(['total_slots' => 0]);
        [$event, $owner] = offerEvent();
        $id = checkoutOf($this, $event, $owner)->assertCreated()->json('data.purchaseId');
        $flag = new ReflectionMethod(PurchaseService::class, 'flagOversold');
        $service = app(PurchaseService::class);

        $flag->invoke($service, $id); // still pending
        EventPurchase::whereKey($id)->update(['status' => 'refunded']);
        $flag->invoke($service, $id);
        EventPurchase::whereKey($id)->update(['status' => 'paid', 'launch_offer_id' => null]);
        $flag->invoke($service, $id);
        $flag->invoke($service, 999999);

        expect(EventPurchase::find($id)->needs_review)->toBeFalse();
    });

    it('counts the admin correction as used slots', function () {
        $offer = makeOffer(['total_slots' => 5, 'used_adjustment' => 4]);
        [$event, $owner] = offerEvent();
        [$other, $otherOwner] = offerEvent();

        quoteOf($this, $event, $owner)->assertJsonPath('data.offer.slotsLeft', 1);
        $id = checkoutOf($this, $event, $owner)->assertCreated()->json('data.purchaseId');
        payPurchase($this, $id);

        quoteOf($this, $other, $otherOwner)->assertJsonPath('data.amountCents', 9900);
        expect($offer->usedSlots())->toBe(5);
    });

    it('does not break a manual payment or the duplicate-payment refund', function () {
        $offer = makeOffer(['total_slots' => 5]);
        [$event, $owner] = offerEvent();
        $first = checkoutOf($this, $event, $owner)->assertCreated()->json('data.purchaseId');
        EventPurchase::whereKey($first)->update(['status' => 'failed']);
        $second = checkoutOf($this, $event, $owner)->assertCreated()->json('data.purchaseId');
        $late = EventPurchase::find($first);
        $late->update(['status' => 'pending', 'gateway_session_id' => 'cs_late']);
        payPurchase($this, $second);
        payPurchase($this, $late->id);

        expect($late->fresh())->status->toBe('refunded')->failure_code->toBe('duplicate_payment')
            ->and(EventPurchase::find($second)->status)->toBe('paid')
            ->and($offer->usedSlots())->toBe(1);
    });
});

describe('offer and coupons', function () {
    it('never stacks: LANZAMIENTO79 gives 79 once, spends its use AND one slot of the offer', function () {
        $this->seed(LaunchCouponSeeder::class);
        $offer = makeOffer(['total_slots' => 2]);
        [$event, $owner] = offerEvent();

        quoteOf($this, $event, $owner, LaunchCouponSeeder::CODE)->assertOk()
            ->assertJsonPath('data.amountCents', 7900)->assertJsonPath('data.source', 'coupon')->assertJsonPath('data.code', 'LANZAMIENTO79');

        $id = checkoutOf($this, $event, $owner, ['code' => 'lanzamiento79', 'expectedAmountCents' => 7900])->assertCreated()->json('data.purchaseId');
        payPurchase($this, $id);

        expect(EventPurchase::find($id))->amount_cents->toBe(7900)->launch_offer_id->toBe($offer->id)->is_founder->toBeTrue()
            ->and(Coupon::where('code', 'LANZAMIENTO79')->value('times_used'))->toBe(1)
            ->and($offer->usedSlots())->toBe(1)->and($offer->slotsLeft())->toBe(1);
    });

    it('shares ONE cap of slots between the public offer and the code', function () {
        $this->seed(LaunchCouponSeeder::class);
        $offer = makeOffer(['total_slots' => 2]);
        [$a, $ownerA] = offerEvent();
        [$b, $ownerB] = offerEvent();
        [$c, $ownerC] = offerEvent();

        payPurchase($this, checkoutOf($this, $a, $ownerA)->assertCreated()->json('data.purchaseId'));
        payPurchase($this, checkoutOf($this, $b, $ownerB, ['code' => 'LANZAMIENTO79'])->assertCreated()->json('data.purchaseId'));

        expect($offer->slotsLeft())->toBe(0);
        quoteOf($this, $c, $ownerC)->assertJsonPath('data.amountCents', 9900);
        foreach ([fn () => quoteOf($this, $c, $ownerC, 'LANZAMIENTO79'), fn () => checkoutOf($this, $c, $ownerC, ['code' => 'LANZAMIENTO79', 'expectedAmountCents' => 7900])] as $try) {
            $try()->assertStatus(422)->assertJsonPath('errors.code.0', 'launch_offer_exhausted');
        }
        expect(EventPurchase::where('event_id', $c->id)->count())->toBe(0)->and(Coupon::where('code', 'LANZAMIENTO79')->value('times_used'))->toBe(1);
    });

    it('gives the code no discount when the offer is switched off, and a refund frees its slot', function () {
        $this->seed(LaunchCouponSeeder::class);
        $offer = makeOffer(['total_slots' => 1]);
        [$a, $ownerA] = offerEvent();
        [$b, $ownerB] = offerEvent();
        $id = checkoutOf($this, $a, $ownerA, ['code' => 'LANZAMIENTO79'])->assertCreated()->json('data.purchaseId');
        payPurchase($this, $id);
        quoteOf($this, $b, $ownerB, 'LANZAMIENTO79')->assertStatus(422)->assertJsonPath('errors.code.0', 'launch_offer_exhausted');

        app(PurchaseService::class)->refund(EventPurchase::find($id), 'asked');
        quoteOf($this, $b, $ownerB, 'LANZAMIENTO79')->assertOk()->assertJsonPath('data.amountCents', 7900);

        $offer->update(['is_active' => false]);
        quoteOf($this, $b, $ownerB, 'LANZAMIENTO79')->assertStatus(422)->assertJsonPath('errors.code.0', 'launch_offer_exhausted');
    });

    it('keeps the code\'s own max uses as a second cap', function () {
        $this->seed(LaunchCouponSeeder::class);
        makeOffer(['total_slots' => 5]);
        Coupon::where('code', 'LANZAMIENTO79')->update(['times_used' => 100]);
        [$event, $owner] = offerEvent();

        quoteOf($this, $event, $owner, 'LANZAMIENTO79')->assertStatus(422)->assertJsonPath('errors.code.0', 'invalid_code');
    });

    it('uses the code with no offer at all exactly as before (not tied to anything)', function () {
        $this->seed(LaunchCouponSeeder::class);
        [$event, $owner] = offerEvent();

        quoteOf($this, $event, $owner, 'LANZAMIENTO79')->assertOk()->assertJsonPath('data.amountCents', 7900);
        expect(Coupon::where('code', 'LANZAMIENTO79')->value('launch_offer_id'))->toBeNull();
    });

    it('lets a 50 percent code beat the offer without taking a slot', function () {
        $offer = makeOffer();
        Coupon::create(['code' => 'HALFB', 'kind' => 'percent_off', 'value' => 50]);
        [$event, $owner] = offerEvent();

        $id = checkoutOf($this, $event, $owner, ['code' => 'HALFB', 'expectedAmountCents' => 4950])->assertCreated()->json('data.purchaseId');
        payPurchase($this, $id);

        expect(EventPurchase::find($id))->amount_cents->toBe(4950)->launch_offer_id->toBeNull()->and($offer->usedSlots())->toBe(0);
    });

    it('gives the best price: a bigger coupon wins, a smaller one is ignored and not spent', function () {
        $offer = makeOffer();
        Coupon::create(['code' => 'HALF', 'kind' => 'percent_off', 'value' => 50]);
        Coupon::create(['code' => 'TEN', 'kind' => 'percent_off', 'value' => 10, 'max_uses' => 3]);
        [$event, $owner] = offerEvent();

        quoteOf($this, $event, $owner, 'HALF')->assertJsonPath('data.amountCents', 4950)->assertJsonPath('data.source', 'coupon');
        quoteOf($this, $event, $owner, 'TEN')->assertJsonPath('data.amountCents', 7900)->assertJsonPath('data.source', 'offer')->assertJsonPath('data.code', null);

        $id = checkoutOf($this, $event, $owner, ['code' => 'TEN'])->assertCreated()->json('data.purchaseId');
        payPurchase($this, $id);

        expect(EventPurchase::find($id))->amount_cents->toBe(7900)->coupon_id->toBeNull()->launch_offer_id->toBe($offer->id)
            ->and(Coupon::where('code', 'TEN')->value('times_used'))->toBe(0);
    });

    it('lets a 100 percent code through and charges nothing, with no slot used', function () {
        $offer = makeOffer();
        Coupon::create(['code' => 'GIFT', 'kind' => 'percent_off', 'value' => 100]);
        [$event, $owner] = offerEvent();

        checkoutOf($this, $event, $owner, ['code' => 'GIFT'])->assertCreated();

        expect($event->fresh()->eventPlan->slug)->toBe('premium')->and($offer->usedSlots())->toBe(0)
            ->and(EventPurchase::first())->amount_cents->toBe(0)->launch_offer_id->toBeNull();
    });

    it('rejects an invalid code even with the offer open', function () {
        makeOffer();
        [$event, $owner] = offerEvent();

        quoteOf($this, $event, $owner, 'NOPE')->assertStatus(422)->assertJsonPath('errors.code.0', 'invalid_code');
    });

    it('keeps Stripe\'s 50 cent minimum', function () {
        makeOffer();
        Coupon::create(['code' => 'TINY', 'kind' => 'fixed_price', 'value' => 10]);
        [$event, $owner] = offerEvent();

        quoteOf($this, $event, $owner, 'TINY')->assertStatus(422)->assertJsonPath('errors.code.0', 'amount_below_minimum');
        checkoutOf($this, $event, $owner, ['code' => 'TINY'])->assertStatus(422);
        expect(EventPurchase::count())->toBe(0);
    });
});

describe('seeders', function () {
    it('creates the offer once and leaves an edited one alone', function () {
        $this->seed(LaunchOfferSeeder::class);
        expect(LaunchOffer::count())->toBe(1)->and(LaunchOffer::first())->price_cents->toBe(7900)->total_slots->toBe(100)->is_active->toBeTrue();

        LaunchOffer::first()->update(['is_active' => false, 'total_slots' => 40]);
        $this->seed(LaunchOfferSeeder::class);

        expect(LaunchOffer::count())->toBe(1)->and(LaunchOffer::first())->total_slots->toBe(40)->is_active->toBeFalse();
    });

    it('does nothing without the premium plan', function () {
        EventPlan::where('slug', 'premium')->delete();
        $this->seed(LaunchOfferSeeder::class);

        expect(LaunchOffer::count())->toBe(0);
    });

    it('ties the founder code to the offer when the offer seeder runs first', function () {
        $this->seed(LaunchOfferSeeder::class);
        $this->seed(LaunchCouponSeeder::class);
        $this->seed(LaunchOfferSeeder::class);

        expect(Coupon::where('code', 'LANZAMIENTO79')->value('launch_offer_id'))->toBe(LaunchOffer::first()->id);
    });

    it('ties the founder code to the offer when the coupon seeder runs first', function () {
        $this->seed(LaunchCouponSeeder::class);
        $this->seed(LaunchOfferSeeder::class);
        Coupon::where('code', 'LANZAMIENTO79')->update(['launch_offer_id' => null]);
        $this->seed(LaunchCouponSeeder::class);

        expect(Coupon::where('code', 'LANZAMIENTO79')->value('launch_offer_id'))->toBe(LaunchOffer::first()->id);
    });

    it('is loaded by the demo seeder', function () {
        $this->seed(DemoSeeder::class);

        expect(LaunchOffer::count())->toBe(1);
    });
});

describe('admin', function () {
    function superadmin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');

        return $admin;
    }

    it('shows null until an offer exists, then creates it with the first PUT and audits it', function () {
        $admin = superadmin();
        $this->actingAsReauthed($admin)->getJson('/api/v1/admin/payments/launch-offer')->assertOk()->assertJsonPath('data', null);

        $this->actingAsReauthed($admin)->putJson('/api/v1/admin/payments/launch-offer', ['isActive' => true, 'reason' => 'launch'])->assertStatus(422);
        $this->actingAsReauthed($admin)->putJson('/api/v1/admin/payments/launch-offer', ['priceCents' => 7900, 'totalSlots' => 100, 'isActive' => true, 'reason' => 'Launch day'])
            ->assertOk()
            ->assertJsonPath('data', ['id' => LaunchOffer::first()->id, 'isActive' => true, 'active' => true, 'priceCents' => 7900, 'listPriceCents' => 9900, 'currency' => 'USD',
                'totalSlots' => 100, 'slotsUsed' => 0, 'slotsPaid' => 0, 'slotsAdjustment' => 0, 'slotsHeld' => 0, 'slotsLeft' => 100]);

        $log = AdminAuditLog::where('action', 'launch_offer.update')->first();
        expect($log)->reason->toBe('Launch day')->subject_type->toBe('launch_offer')->and($log->before)->toBeNull()->and($log->after['totalSlots'])->toBe(100);
    });

    it('turns it off, changes slots and corrects the used count, each with a reason in the audit log', function () {
        $admin = superadmin();
        $offer = makeOffer();
        [$event, $owner] = offerEvent();
        payPurchase($this, checkoutOf($this, $event, $owner)->assertCreated()->json('data.purchaseId'));

        $this->actingAsReauthed($admin)->putJson('/api/v1/admin/payments/launch-offer', ['totalSlots' => 50, 'slotsUsed' => 10, 'reason' => 'Sold 9 by Zelle'])
            ->assertOk()->assertJsonPath('data.slotsUsed', 10)->assertJsonPath('data.slotsPaid', 1)->assertJsonPath('data.slotsAdjustment', 9)->assertJsonPath('data.slotsLeft', 40);
        $this->actingAsReauthed($admin)->putJson('/api/v1/admin/payments/launch-offer', ['isActive' => false, 'reason' => 'Pause the offer'])
            ->assertOk()->assertJsonPath('data.isActive', false)->assertJsonPath('data.active', false)->assertJsonPath('data.totalSlots', 50);

        expect(AdminAuditLog::where('action', 'launch_offer.deactivate')->first())->reason->toBe('Pause the offer')
            ->and(AdminAuditLog::where('action', 'launch_offer.deactivate')->first()->before['isActive'])->toBeTrue();
        [$other, $otherOwner] = offerEvent();
        quoteOf($this, $other, $otherOwner)->assertJsonPath('data.amountCents', 9900);
        $this->actingAsReauthed($admin)->getJson('/api/v1/admin/payments/launch-offer')->assertOk()->assertJsonPath('data.slotsUsed', 10);
    });

    it('validates the payload', function () {
        $admin = superadmin();
        makeOffer();
        $put = fn (array $b) => $this->actingAsReauthed($admin)->putJson('/api/v1/admin/payments/launch-offer', $b);

        $put(['priceCents' => 7900])->assertStatus(422)->assertJsonValidationErrors('reason');
        $put(['priceCents' => 7900, 'reason' => 'abc'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $put(['priceCents' => 10, 'reason' => 'too cheap'])->assertStatus(422)->assertJsonValidationErrors('priceCents');
        $put(['priceCents' => 9900, 'reason' => 'not a discount'])->assertStatus(422)->assertJsonValidationErrors('priceCents');
        $put(['totalSlots' => -1, 'reason' => 'negative'])->assertStatus(422)->assertJsonValidationErrors('totalSlots');
        $put(['slotsUsed' => -3, 'reason' => 'negative'])->assertStatus(422)->assertJsonValidationErrors('slotsUsed');
        expect(LaunchOffer::first())->price_cents->toBe(7900)->total_slots->toBe(100);
    });

    it('needs a login', function () {
        $this->getJson('/api/v1/admin/payments/launch-offer')->assertUnauthorized();
        $this->putJson('/api/v1/admin/payments/launch-offer', ['totalSlots' => 1, 'reason' => 'anonymous'])->assertUnauthorized();
    });

    it('is for the superadmin only, behind re-authentication, and never for organizers', function () {
        makeOffer();
        $support = User::factory()->create();
        $support->assignRole('support');
        $organizer = User::factory()->create();

        $this->actingAsReauthed($support)->getJson('/api/v1/admin/payments/launch-offer')->assertForbidden();
        $this->actingAsReauthed($support)->putJson('/api/v1/admin/payments/launch-offer', ['totalSlots' => 1, 'reason' => 'support tries'])->assertForbidden();
        $this->actingAsReauthed($organizer)->getJson('/api/v1/admin/payments/launch-offer')->assertForbidden();
        $this->actingAsReauthed($organizer)->putJson('/api/v1/admin/payments/launch-offer', ['totalSlots' => 1, 'reason' => 'organizer tries'])->assertForbidden();
        $this->actingAs(superadmin())->putJson('/api/v1/admin/payments/launch-offer', ['totalSlots' => 1, 'reason' => 'no reauth at all'])->assertStatus(403);

        expect(LaunchOffer::first()->total_slots)->toBe(100)->and(AdminAuditLog::where('action', 'like', 'launch_offer%')->count())->toBe(0);
    });
});
