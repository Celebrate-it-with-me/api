<?php

namespace App\Http\Services\Payments;

use App\Http\Services\Invoices\InvoiceService;
use App\Jobs\DeliverCreditNoteEmail;
use App\Jobs\DeliverInvoiceEmail;
use App\Models\Coupon;
use App\Models\EventPlan;
use App\Models\EventPurchase;
use App\Models\Events;
use App\Models\LaunchOffer;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Buying the full event (docs/plans/payments.md). The server decides the amount; the webhook (or an admin)
 * is what activates the plan, and activating twice is harmless.
 */
class PurchaseService
{
    public const FREE_SLUG = 'basic';

    public const FULL_SLUG = 'premium';

    /** Stripe refuses a charge below 50 cents; zero is fine (nothing is charged) and never reaches the provider. */
    public const MIN_CHARGE_CENTS = 50;

    public function gateway(?string $name = null): PaymentGateway
    {
        $name ??= config('services.payments.gateway');

        // The stand-in gateway activates the plan without a payment: only on a developer machine or in tests, never
        // by default in another environment (P-4: a public server with a wrong APP_ENV must not hand out plans).
        if ($name === 'fake' && ! app()->environment('local', 'testing')) {
            throw new \RuntimeException('The fake payment gateway can only be used in local and testing environments.');
        }

        // An unknown name is a configuration error: it must never fall back to the stand-in in silence.
        return match ($name) {
            'fake' => new FakeGateway,
            'manual' => new ManualGateway,
            'stripe' => StripeGateway::fromConfig(),
            default => throw new \RuntimeException('Unknown payment gateway "' . (string) $name . '".'),
        };
    }

    public function fullPlan(): EventPlan
    {
        return EventPlan::query()->where('slug', self::FULL_SLUG)->firstOrFail();
    }

    public function isUnlocked(Events $event): bool
    {
        return $event->eventPlan?->slug === self::FULL_SLUG;
    }

    public function launchOffer(EventPlan $plan, bool $lock = false): ?LaunchOffer
    {
        return LaunchOffer::query()->where('event_plan_id', $plan->id)->when($lock, fn ($q) => $q->lockForUpdate())->first();
    }

    /**
     * The price rule. The launch offer (no code) and a coupon never stack: the buyer gets whichever is cheaper, ONCE.
     * On a tie the coupon wins and is spent. A coupon that loses is not applied, so it is not spent. A 100 % or 50 %
     * coupon beats the offer and takes no slot. A coupon tied to the offer takes a slot of the offer (one cap of N for both).
     *
     * @return array{amount: int, coupon: Coupon|null, offer: LaunchOffer|null, source: string}
     */
    public function price(EventPlan $plan, ?Coupon $coupon, Events $event, ?LaunchOffer $offer, ?User $buyer = null): array
    {
        $list = (int) $plan->price_cents;
        $couponAmount = $coupon ? $coupon->apply($list) : null;
        $offerAmount = $offer && $offer->isOpen($plan, $event->id, $buyer?->id) ? (int) $offer->price_cents : null;

        // A code tied to the offer (LANZAMIENTO79) shares its slots: with the offer sold out, off, or for someone at her
        // hold cap, the code gives no discount at all and the buyer is told why (the screen shows "the offer ended").
        $linked = $coupon && $coupon->launch_offer_id !== null;
        if ($linked && ($offerAmount === null || (int) $coupon->launch_offer_id !== (int) $offer->id)) {
            throw ValidationException::withMessages(['code' => ['launch_offer_exhausted']]);
        }

        if ($couponAmount !== null && ($offerAmount === null || $couponAmount <= $offerAmount)) {
            // A linked code takes a slot of the offer like the offer itself; any other code (50 %, 100 %...) takes none.
            return ['amount' => $couponAmount, 'coupon' => $coupon, 'offer' => $linked ? $offer : null, 'source' => 'coupon'];
        }
        if ($offerAmount !== null) {
            return ['amount' => $offerAmount, 'coupon' => null, 'offer' => $offer, 'source' => 'offer'];
        }

        return ['amount' => $list, 'coupon' => null, 'offer' => null, 'source' => 'list'];
    }

    /** What the screen shows about the offer: never reveals anything about other events beyond the public count. */
    public function offerSummary(EventPlan $plan, Events $event, ?LaunchOffer $offer = null, ?User $buyer = null): array
    {
        $offer ??= $this->launchOffer($plan);

        return [
            'active' => (bool) $offer?->isOpen($plan, $event->id, $buyer?->id),
            'priceCents' => $offer?->price_cents,
            'listPriceCents' => (int) $plan->price_cents,
            'slotsLeft' => $offer && $offer->is_active ? $offer->slotsLeft($event->id) : 0,
            'totalSlots' => $offer ? (int) $offer->total_slots : 0,
        ];
    }

    /** What the buyer would pay, with an optional code. */
    public function quote(Events $event, ?string $code = null, ?User $buyer = null): array
    {
        $plan = $this->fullPlan();
        $coupon = $code ? $this->resolveCoupon($code) : null;
        $offer = $this->launchOffer($plan);
        $price = $this->price($plan, $coupon, $event, $offer, $buyer);
        $this->assertChargeable($price['amount']);

        return [
            'plan' => $plan->slug,
            'listPriceCents' => $plan->price_cents,
            'amountCents' => $price['amount'],
            'currency' => $plan->currency,
            'maxGuests' => $plan->max_guests,
            'code' => $price['coupon']?->code,
            'source' => $price['source'],
            'offer' => $this->offerSummary($plan, $event, $offer, $buyer),
            'unlocked' => $this->isUnlocked($event),
        ];
    }

    /** A final amount between 1 and 49 cents cannot be charged: reject it clearly instead of failing at the provider. */
    private function assertChargeable(int $amount): void
    {
        if ($amount > 0 && $amount < self::MIN_CHARGE_CENTS) {
            throw ValidationException::withMessages(['code' => ['amount_below_minimum']]);
        }
    }

    public function resolveCoupon(string $code): Coupon
    {
        $coupon = Coupon::query()->where('code', strtoupper(trim($code)))->first();

        if (! $coupon || ! $coupon->isUsable()) {
            throw ValidationException::withMessages(['code' => ['invalid_code']]);
        }

        return $coupon;
    }

    /** How long a pending checkout holds one use of a capped code. */
    private const COUPON_HOLD_MINUTES = 60;

    /** Locks the coupon row and fails when its uses (consumed plus held by recent pending checkouts) are exhausted. */
    private function reserveCoupon(Coupon $coupon): void
    {
        $locked = Coupon::query()->lockForUpdate()->findOrFail($coupon->id);

        if (! $locked->isUsable()) {
            throw ValidationException::withMessages(['code' => ['invalid_code']]);
        }

        if ($locked->max_uses !== null) {
            $held = EventPurchase::query()->where('coupon_id', $locked->id)->where('status', 'pending')
                ->where('created_at', '>', now()->subMinutes(self::COUPON_HOLD_MINUTES))->count();

            if ($locked->times_used + $held >= $locked->max_uses) {
                throw ValidationException::withMessages(['code' => ['invalid_code']]);
            }
        }
    }

    /**
     * Create the pending purchase and start the payment. With a session-based gateway there is one open session per
     * event: a second call for the same price returns it, a different price or code expires it and starts over.
     *
     * @param  array{locale?: string}  $context
     * @return array{purchase: EventPurchase, url: string|null}
     */
    public function checkout(Events $event, User $user, ?string $code, string $successUrl, string $cancelUrl, array $context = [], ?int $expectedAmountCents = null): array
    {
        if ($this->isUnlocked($event)) {
            throw ValidationException::withMessages(['event' => ['already_unlocked']]);
        }

        $plan = $this->fullPlan();
        $coupon = $code ? $this->resolveCoupon($code) : null;
        // A first look to decide whether a pending session can be reused; the price that is charged is decided again
        // below under the offer's lock.
        $first = $this->price($plan, $coupon, $event, $this->launchOffer($plan), $user);
        $this->assertChargeable($first['amount']);
        $gateway = $this->gateway();

        // Two simultaneous clicks (or two tabs) must not each open a session or create a paid purchase, on any gateway.
        $lock = Cache::lock("checkout-event-{$event->id}", 30);
        try {
            $lock->block(10);
        } catch (LockTimeoutException) {
            throw new HttpResponseException(response()->json(['message' => 'A checkout is already being created.', 'code' => 'checkout_in_progress'], 409));
        }

        try {
            // F-1: whoever held the lock before us may have just unlocked the event; look again before creating a purchase.
            if ($this->isUnlocked($event->refresh())) {
                throw ValidationException::withMessages(['event' => ['already_unlocked']]);
            }

            if ($first['amount'] > 0 && $gateway->name() === 'stripe' && ($reused = $this->reuseOpenSession($event, $gateway, $first['coupon'], $first['amount'], $first['offer']))) {
                return $reused;
            }

            // The coupon's cap is checked and the use reserved under a lock, so N simultaneous checkouts of a 1-use code
            // cannot all get through (a use is only counted as consumed when the payment confirms).
            // The launch offer's row is locked too, so N simultaneous checkouts for the last slot cannot all get the offer
            // price: the price is decided again here, one at a time, and the slot is held by the purchase created in it.
            $applied = null;
            $purchase = DB::transaction(function () use ($event, $user, $plan, $coupon, $gateway, $expectedAmountCents, &$applied) {
                $offer = $this->launchOffer($plan, lock: true);
                $price = $this->price($plan, $coupon, $event, $offer, $user);
                $this->assertChargeable($price['amount']);

                // The buyer saw one price and the slot is gone (or came back): tell her instead of charging another.
                if ($expectedAmountCents !== null && $expectedAmountCents !== $price['amount']) {
                    throw new HttpResponseException(response()->json([
                        'message' => 'The price changed.', 'code' => 'price_changed', 'data' => ['amountCents' => $price['amount'], 'offer' => $this->offerSummary($plan, $event, $offer, $user)],
                    ], 409));
                }

                if ($price['coupon']) {
                    $this->reserveCoupon($price['coupon']);
                }
                $applied = $price['coupon'];

                return EventPurchase::create([
                    'event_id' => $event->id, 'user_id' => $user->id, 'event_plan_id' => $plan->id, 'coupon_id' => $price['coupon']?->id,
                    'launch_offer_id' => $price['offer']?->id,
                    'gateway' => $gateway->name(), 'list_price_cents' => $plan->price_cents, 'amount_cents' => $price['amount'],
                    'currency' => $plan->currency, 'status' => 'pending', 'is_founder' => (bool) $price['coupon']?->is_founder,
                ]);
            });
            $amount = (int) $purchase->amount_cents;

            // Nothing to charge: the provider is not involved (it would refuse a zero amount).
            if ($amount === 0) {
                $purchase = $this->markPaid($purchase);

                return ['purchase' => $purchase, 'url' => $successUrl];
            }

            try {
                $session = $gateway->createSession($purchase, $successUrl, $cancelUrl, $context + ['coupon' => $applied?->code]);
            } catch (PaymentGatewayException $e) {
                // The attempt is over and holds nothing (a failed purchase releases its coupon use).
                $purchase->update(['status' => 'failed', 'failure_code' => 'gateway_error']);
                Log::error('Payment session could not be created', ['purchase_id' => $purchase->id, 'error' => $e->getMessage()]);
                throw $e;
            }

            $purchase->update(['gateway_session_id' => $session['id'], 'session_expires_at' => $session['expiresAt'] ?? null]);

            // The stand-in gateway on a developer machine: no one is going to pay anywhere, so confirm now.
            // In production the webhook is the only thing that activates the plan.
            if ($gateway instanceof FakeGateway && app()->environment('local')) {
                $purchase = $this->markPaid($purchase);
            }

            return ['purchase' => $purchase, 'url' => $session['url']];
        } finally {
            $lock->release();
        }
    }

    /** The pending session of this event if it is still usable for the same price; any other is closed and marked failed. */
    private function reuseOpenSession(Events $event, PaymentGateway $gateway, ?Coupon $coupon, int $amount, ?LaunchOffer $offer = null): ?array
    {
        $pending = EventPurchase::query()->where('event_id', $event->id)->where('gateway', 'stripe')->where('status', 'pending')
            ->whereNotNull('gateway_session_id')->orderByDesc('id')->get();

        foreach ($pending as $candidate) {
            $reusable = (int) $candidate->amount_cents === $amount && $candidate->coupon_id === $coupon?->id && $candidate->launch_offer_id === $offer?->id
                && (! $candidate->session_expires_at || $candidate->session_expires_at->isFuture());

            try {
                if ($reusable) {
                    $session = $gateway->retrieveSession($candidate->gateway_session_id);
                    if ($session['state'] === 'open' && $session['url']) {
                        return ['purchase' => $candidate, 'url' => $session['url']];
                    }
                    // Paid or closed meanwhile: the webhook / reconciliation settles it; start a new attempt only if it is dead.
                    if ($session['state'] === 'complete') {
                        throw new HttpResponseException(response()->json(['message' => 'A payment is being confirmed.', 'code' => 'payment_in_progress'], 409));
                    }
                } else {
                    $this->closeSession($gateway, $candidate);
                }
            } catch (PaymentGatewayException $e) {
                // LO-002: the session may still be open and payable. The purchase stays pending (the payment would still match
                // it), nothing new is started on top of it, and the buyer retries in a moment.
                Log::error('Could not close or read a previous session: purchase left pending', ['purchase_id' => $candidate->id, 'error' => $e->getMessage()]);
                throw new HttpResponseException(response()->json(['message' => 'The previous payment session could not be closed yet.', 'code' => 'previous_session_open'], 409));
            }

            EventPurchase::query()->whereKey($candidate->id)->where('status', 'pending')
                ->update(['status' => 'failed', 'failure_code' => $reusable ? 'expired' : 'superseded']);
        }

        return null;
    }

    /**
     * Close a pending session. Expiring one Stripe already closed is an error there, so on failure the session is read: if it is
     * expired it is fine; if it was paid meanwhile the purchase must not be failed.
     *
     * @throws PaymentGatewayException when it cannot be established that the session is closed
     */
    private function closeSession(PaymentGateway $gateway, EventPurchase $purchase): void
    {
        try {
            $gateway->expireSession($purchase->gateway_session_id);

            return;
        } catch (PaymentGatewayException $e) {
            $session = $gateway->retrieveSession($purchase->gateway_session_id); // may throw: still unknown
            if ($session['state'] === 'complete') {
                throw new HttpResponseException(response()->json(['message' => 'A payment is being confirmed.', 'code' => 'payment_in_progress'], 409));
            }
            if ($session['state'] !== 'expired') {
                throw $e;
            }
        }
    }

    /** Payment confirmed: activate the full plan. Idempotent. */
    public function markPaid(EventPurchase $purchase, ?string $paymentId = null): EventPurchase
    {
        $purchase = $this->activate($purchase, $paymentId);
        $this->invoice($purchase);

        return $purchase;
    }

    /**
     * Every confirmed payment gets its invoice (docs/plans/invoices.md), by mail the first time. Issuing is idempotent,
     * and a failure here never undoes a payment that already happened: it is logged and `invoices:backfill` retries.
     */
    public function invoice(EventPurchase $purchase): void
    {
        try {
            $invoices = app(InvoiceService::class);
            [$invoice, $created] = $invoices->issueForPurchase($purchase);
            if ($created) {
                DeliverInvoiceEmail::dispatch($invoice->id);
            }
        } catch (\Throwable $e) {
            Log::error('Invoice issuing or delivery failed', ['purchase_id' => $purchase->id, 'error' => $e->getMessage()]);
            report($e);
        }
    }

    public function activate(EventPurchase $purchase, ?string $paymentId): EventPurchase
    {
        return DB::transaction(function () use ($purchase, $paymentId) {
            $purchase = EventPurchase::query()->lockForUpdate()->findOrFail($purchase->id);

            if ($purchase->status === 'paid') {
                return $purchase;
            }

            $purchase->update([
                'status' => 'paid', 'paid_at' => now(), 'failure_code' => null,
                'gateway_payment_id' => $paymentId ?? $purchase->gateway_payment_id,
            ]);
            $purchase->event()->update(['event_plan_id' => $purchase->event_plan_id]);

            if ($purchase->coupon_id) {
                Coupon::query()->whereKey($purchase->coupon_id)->increment('times_used');
            }

            // Paid on the launch offer: the slot is now consumed (it is counted from the paid purchases). Whether it went
            // over the cap is looked at once the payment is committed.
            if ($purchase->launch_offer_id) {
                $purchaseId = $purchase->id;
                DB::afterCommit(fn () => $this->flagOversold($purchaseId));
            }

            return $purchase->refresh();
        });
    }

    /**
     * Two payments the checkout could not tell apart (a hold that expired while the buyer was still paying) can exceed the
     * cap. The money is real and the price shown was honoured: nothing is refunded or blocked, the purchase is only flagged
     * for a person and logged.
     */
    private function flagOversold(int $purchaseId): void
    {
        $purchase = EventPurchase::query()->find($purchaseId);
        $offer = $purchase?->launch_offer_id ? LaunchOffer::query()->find($purchase->launch_offer_id) : null;
        if (! $purchase || ! $offer || $purchase->status !== 'paid') {
            return;
        }

        // This payment is the latest to commit: if the committed total (paid plus the admin correction) is over the cap, it is the one that went over.
        $position = $offer->usedSlots();

        if ($position > (int) $offer->total_slots && ! $purchase->needs_review) {
            $purchase->update(['needs_review' => true, 'notes' => trim(($purchase->notes ? $purchase->notes . ' ' : '') . 'launch_offer_oversold')]);
            Log::warning('Launch offer oversold: the price shown was honoured', ['purchase_id' => $purchase->id, 'offer_id' => $offer->id, 'position' => $position, 'total_slots' => $offer->total_slots]);
        }
    }

    /** Record a payment made outside the gateway (Zelle, cash). */
    public function recordManual(Events $event, User $buyer, User $admin, int $amountCents, ?string $notes): EventPurchase
    {
        if ($this->isUnlocked($event->refresh())) {
            throw ValidationException::withMessages(['event' => 'This event is already unlocked: nothing to record.']);
        }
        $plan = $this->fullPlan();

        $purchase = EventPurchase::create([
            'event_id' => $event->id, 'user_id' => $buyer->id, 'event_plan_id' => $plan->id, 'gateway' => 'manual',
            'list_price_cents' => $plan->price_cents, 'amount_cents' => $amountCents, 'currency' => $plan->currency,
            'status' => 'pending', 'notes' => $notes, 'created_by' => $admin->id,
        ]);

        return $this->markPaid($purchase);
    }

    /** Refunds are full and only while the invitation is not published (the event goes back to the free plan). */
    public function refund(EventPurchase $purchase, ?string $reason = null): EventPurchase
    {
        $note = null;
        $refunded = DB::transaction(function () use ($purchase, $reason, &$note) {
            // Checked on the locked row, so two simultaneous refunds cannot both reach the gateway.
            $purchase = EventPurchase::query()->lockForUpdate()->findOrFail($purchase->id);

            if ($purchase->status !== 'paid') {
                throw ValidationException::withMessages(['purchase' => ['not_paid']]);
            }
            if ($purchase->event->status === 'published') {
                throw ValidationException::withMessages(['purchase' => ['event_published']]);
            }

            $purchase->refund_reason = mb_substr((string) $reason, 0, 500) ?: null;
            $refundId = $this->gateway($purchase->gateway)->refund($purchase);
            $purchase->update(['status' => 'refunded', 'refunded_at' => now(), 'gateway_refund_id' => $refundId]);
            $note = app(InvoiceService::class)->creditForRefund($purchase, $reason ?: 'Refund');
            $free = EventPlan::query()->where('slug', self::FREE_SLUG)->value('id');
            $purchase->event()->update(['event_plan_id' => $free]);

            return $purchase->refresh();
        });

        // After the commit, so a mail failure can never undo the refund (the service swallows and logs it).
        if ($note) {
            DeliverCreditNoteEmail::dispatch($note->id);
        }

        return $refunded;
    }
}
