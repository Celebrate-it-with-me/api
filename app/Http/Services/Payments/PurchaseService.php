<?php

namespace App\Http\Services\Payments;

use App\Http\Services\Invoices\InvoiceService;
use App\Jobs\DeliverCreditNoteEmail;
use App\Jobs\DeliverInvoiceEmail;
use App\Models\Coupon;
use App\Models\EventPlan;
use App\Models\EventPurchase;
use App\Models\Events;
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

    /** What the buyer would pay, with an optional code. */
    public function quote(Events $event, ?string $code = null): array
    {
        $plan = $this->fullPlan();
        $coupon = $code ? $this->resolveCoupon($code) : null;
        $amount = $coupon ? $coupon->apply($plan->price_cents) : $plan->price_cents;
        $this->assertChargeable($amount);

        return [
            'plan' => $plan->slug,
            'listPriceCents' => $plan->price_cents,
            'amountCents' => $amount,
            'currency' => $plan->currency,
            'maxGuests' => $plan->max_guests,
            'code' => $coupon?->code,
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
    public function checkout(Events $event, User $user, ?string $code, string $successUrl, string $cancelUrl, array $context = []): array
    {
        if ($this->isUnlocked($event)) {
            throw ValidationException::withMessages(['event' => ['already_unlocked']]);
        }

        $plan = $this->fullPlan();
        $coupon = $code ? $this->resolveCoupon($code) : null;
        $amount = $coupon ? $coupon->apply($plan->price_cents) : $plan->price_cents;
        $this->assertChargeable($amount);
        $gateway = $this->gateway();

        // Two simultaneous clicks (or two tabs) must not each open a session.
        $lock = $gateway->name() === 'stripe' ? Cache::lock("checkout-event-{$event->id}", 30) : null;
        try {
            $lock?->block(10);
        } catch (LockTimeoutException) {
            throw new HttpResponseException(response()->json(['message' => 'A checkout is already being created.', 'code' => 'checkout_in_progress'], 409));
        }

        try {
            if ($amount > 0 && $gateway->name() === 'stripe' && ($reused = $this->reuseOpenSession($event, $gateway, $coupon, $amount))) {
                return $reused;
            }

            // The coupon's cap is checked and the use reserved under a lock, so N simultaneous checkouts of a 1-use code
            // cannot all get through (a use is only counted as consumed when the payment confirms).
            $purchase = DB::transaction(function () use ($event, $user, $plan, $coupon, $gateway, $amount) {
                if ($coupon) {
                    $this->reserveCoupon($coupon);
                }

                return EventPurchase::create([
                    'event_id' => $event->id, 'user_id' => $user->id, 'event_plan_id' => $plan->id, 'coupon_id' => $coupon?->id,
                    'gateway' => $gateway->name(), 'list_price_cents' => $plan->price_cents, 'amount_cents' => $amount,
                    'currency' => $plan->currency, 'status' => 'pending', 'is_founder' => (bool) $coupon?->is_founder,
                ]);
            });

            // Nothing to charge: the provider is not involved (it would refuse a zero amount).
            if ($amount === 0) {
                $purchase = $this->markPaid($purchase);

                return ['purchase' => $purchase, 'url' => $successUrl];
            }

            try {
                $session = $gateway->createSession($purchase, $successUrl, $cancelUrl, $context + ['coupon' => $coupon?->code]);
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
            $lock?->release();
        }
    }

    /** The pending session of this event if it is still usable for the same price; any other is closed and marked failed. */
    private function reuseOpenSession(Events $event, PaymentGateway $gateway, ?Coupon $coupon, int $amount): ?array
    {
        $pending = EventPurchase::query()->where('event_id', $event->id)->where('gateway', 'stripe')->where('status', 'pending')
            ->whereNotNull('gateway_session_id')->orderByDesc('id')->get();

        foreach ($pending as $candidate) {
            $reusable = (int) $candidate->amount_cents === $amount && $candidate->coupon_id === $coupon?->id
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
                    $gateway->expireSession($candidate->gateway_session_id);
                }
            } catch (PaymentGatewayException $e) {
                Log::warning('Could not close or read a previous session', ['purchase_id' => $candidate->id, 'error' => $e->getMessage()]);
            }

            EventPurchase::query()->whereKey($candidate->id)->where('status', 'pending')
                ->update(['status' => 'failed', 'failure_code' => $reusable ? 'expired' : 'superseded']);
        }

        return null;
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

            return $purchase->refresh();
        });
    }

    /** Record a payment made outside the gateway (Zelle, cash). */
    public function recordManual(Events $event, User $buyer, User $admin, int $amountCents, ?string $notes): EventPurchase
    {
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
