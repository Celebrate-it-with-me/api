<?php

namespace App\Http\Services\Payments;

use App\Http\Services\Invoices\InvoiceService;
use App\Models\Coupon;
use App\Models\EventPlan;
use App\Models\EventPurchase;
use App\Models\Events;
use App\Models\User;
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

    public function gateway(?string $name = null): PaymentGateway
    {
        return match ($name ?? config('services.payments.gateway')) {
            'manual' => new ManualGateway,
            default => new FakeGateway,
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

    public function resolveCoupon(string $code): Coupon
    {
        $coupon = Coupon::query()->where('code', strtoupper(trim($code)))->first();

        if (! $coupon || ! $coupon->isUsable()) {
            throw ValidationException::withMessages(['code' => ['invalid_code']]);
        }

        return $coupon;
    }

    /** Create the pending purchase and start the payment. */
    public function checkout(Events $event, User $user, ?string $code, string $successUrl, string $cancelUrl): array
    {
        if ($this->isUnlocked($event)) {
            throw ValidationException::withMessages(['event' => ['already_unlocked']]);
        }

        $plan = $this->fullPlan();
        $coupon = $code ? $this->resolveCoupon($code) : null;
        $amount = $coupon ? $coupon->apply($plan->price_cents) : $plan->price_cents;
        $gateway = $this->gateway();

        $purchase = EventPurchase::create([
            'event_id' => $event->id, 'user_id' => $user->id, 'event_plan_id' => $plan->id, 'coupon_id' => $coupon?->id,
            'gateway' => $gateway->name(), 'list_price_cents' => $plan->price_cents, 'amount_cents' => $amount,
            'currency' => $plan->currency, 'status' => 'pending', 'is_founder' => (bool) $coupon?->is_founder,
        ]);

        $session = $gateway->createSession($purchase, $successUrl, $cancelUrl);
        $purchase->update(['gateway_session_id' => $session['id']]);

        // Nothing to charge (a free code), or the stand-in gateway on a developer machine: no one is going to pay
        // anywhere, so confirm now. In production the webhook is the only thing that activates the plan.
        if ($amount === 0 || ($gateway instanceof FakeGateway && app()->environment('local'))) {
            $purchase = $this->markPaid($purchase);
        }

        return ['purchase' => $purchase, 'url' => $session['url']];
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
    private function invoice(EventPurchase $purchase): void
    {
        try {
            $invoices = app(InvoiceService::class);
            [$invoice, $created] = $invoices->issueForPurchase($purchase);
            if ($created) {
                $invoices->deliver($invoice);
            }
        } catch (\Throwable $e) {
            Log::error('Invoice issuing or delivery failed', ['purchase_id' => $purchase->id, 'error' => $e->getMessage()]);
            report($e);
        }
    }

    private function activate(EventPurchase $purchase, ?string $paymentId): EventPurchase
    {
        return DB::transaction(function () use ($purchase, $paymentId) {
            $purchase = EventPurchase::query()->lockForUpdate()->findOrFail($purchase->id);

            if ($purchase->status === 'paid') {
                return $purchase;
            }

            $purchase->update(['status' => 'paid', 'paid_at' => now(), 'gateway_payment_id' => $paymentId ?? $purchase->gateway_payment_id]);
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
        if ($purchase->status !== 'paid') {
            throw ValidationException::withMessages(['purchase' => ['not_paid']]);
        }
        if ($purchase->event->status === 'published') {
            throw ValidationException::withMessages(['purchase' => ['event_published']]);
        }

        return DB::transaction(function () use ($purchase, $reason) {
            $this->gateway($purchase->gateway)->refund($purchase);
            $purchase->update(['status' => 'refunded', 'refunded_at' => now()]);
            app(InvoiceService::class)->creditForRefund($purchase, $reason ?: 'Refund');
            $free = EventPlan::query()->where('slug', self::FREE_SLUG)->value('id');
            $purchase->event()->update(['event_plan_id' => $free]);

            return $purchase->refresh();
        });
    }
}
