<?php

namespace App\Http\Services\Payments;

use App\Http\Services\Invoices\InvoiceService;
use App\Models\EventPlan;
use App\Models\EventPurchase;
use App\Models\PaymentEvent;
use App\Support\InvitationCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Applies what the payment gateway reports (webhook or reconciliation) to the purchases. Rules: a state only moves
 * forward (a late or repeated event never takes a paid purchase back), everything happens on the locked row, and an
 * amount or currency that does not match what we asked for activates nothing and flags the purchase for review.
 */
class PaymentSettlement
{
    /** The status endpoint reconciles at most once per purchase in this window. */
    public const RECONCILE_EVERY_SECONDS = 15;

    public function __construct(private readonly PurchaseService $purchases) {}

    /** Process one verified webhook event. Returns the outcome: processed | ignored | mismatch | duplicate. */
    public function handle(string $gateway, GatewayEvent $event): string
    {
        $after = [];

        $outcome = DB::transaction(function () use ($gateway, $event, &$after) {
            // The unique event id makes a delivered event a no-op the second time; a concurrent twin waits for this commit.
            $inserted = DB::table('payment_events')->insertOrIgnore([
                'gateway' => $gateway, 'event_id' => $event->id, 'type' => $event->type, 'outcome' => 'processed', 'received_at' => now(),
            ]);
            if (! $inserted) {
                return 'duplicate';
            }

            [$outcome, $purchase] = $this->apply($event, $after);

            PaymentEvent::query()->where('event_id', $event->id)->update([
                'purchase_id' => $purchase?->id, 'outcome' => $outcome, 'processed_at' => now(),
            ]);

            return $outcome;
        });

        // After the commit, so a mail failure can never undo a payment.
        foreach ($after as $callback) {
            $callback();
        }

        return $outcome;
    }

    /** @return array{0: string, 1: EventPurchase|null} */
    private function apply(GatewayEvent $event, array &$after): array
    {
        if ($event->kind === GatewayEvent::IGNORED) {
            return ['ignored', null];
        }

        $purchase = $this->find($event);
        if (! $purchase) {
            Log::warning('Payment event for an unknown purchase', ['event_id' => $event->id, 'type' => $event->type]);

            return ['ignored', null];
        }

        // The reference the provider echoes must be the purchase we found.
        foreach ([$event->reference, $event->metadataReference] as $ref) {
            if ($ref !== null && $ref !== '' && (string) $purchase->id !== $ref) {
                return [$this->flag($purchase, 'reference_mismatch'), $purchase];
            }
        }

        $outcome = match ($event->kind) {
            GatewayEvent::PAID => $this->paid($purchase, $event->paymentId, $event->amount, $event->currency, $after, $event->taxCents),
            GatewayEvent::FAILED => $this->fail($purchase, 'payment_failed'),
            GatewayEvent::EXPIRED => $this->fail($purchase, 'expired'),
            GatewayEvent::REFUNDED => $this->externalRefund($purchase, $event, $after),
            GatewayEvent::DISPUTE_CREATED => $this->disputeCreated($purchase, $event),
            GatewayEvent::DISPUTE_CLOSED => $this->disputeClosed($purchase, $event, $after),
            default => 'ignored',
        };

        return [$outcome, $purchase];
    }

    private function find(GatewayEvent $event): ?EventPurchase
    {
        $lookups = [];
        if ($event->sessionId) {
            $lookups[] = fn ($q) => $q->where('gateway_session_id', $event->sessionId);
        }
        if ($event->paymentId) {
            $lookups[] = fn ($q) => $q->where('gateway_payment_id', $event->paymentId);
        }
        // The webhook can beat the update that stores the session id: the echoed reference still finds the purchase.
        $ref = $event->reference ?? $event->metadataReference;
        if (ctype_digit((string) $ref)) {
            $lookups[] = fn ($q) => $q->whereKey((int) $ref);
        }

        foreach ($lookups as $lookup) {
            $found = $lookup(EventPurchase::query()->lockForUpdate())->first();
            if ($found) {
                return $found;
            }
        }

        return null;
    }

    /** Confirmed payment (on a locked purchase). @param  array<int, \Closure>  $after */
    private function paid(EventPurchase $purchase, ?string $paymentId, ?int $amount, ?string $currency, array &$after, ?int $tax = null): string
    {
        if (in_array($purchase->status, ['refunded', 'chargeback'], true)) {
            return 'ignored';
        }
        if ($purchase->status === 'paid') {
            return 'ignored'; // already applied: a repeat must not count twice
        }

        // What was charged must be what was asked for. Answered 200 anyway: a retry could never fix it.
        if (($amount !== null && $amount !== (int) $purchase->amount_cents)
            || ($currency !== null && strtolower($currency) !== strtolower((string) $purchase->currency))) {
            $purchase->update(['needs_review' => true, 'failure_code' => 'amount_mismatch', 'gateway_payment_id' => $paymentId ?? $purchase->gateway_payment_id]);
            Log::warning('Payment amount does not match the purchase', ['purchase_id' => $purchase->id]);

            return 'mismatch';
        }

        // The event is already paid for by another purchase: take the money back at once, do not invoice twice.
        $other = EventPurchase::query()->where('event_id', $purchase->event_id)->where('status', 'paid')->whereKeyNot($purchase->id)->exists();
        if ($other) {
            $purchase->gateway_payment_id = $paymentId ?? $purchase->gateway_payment_id;
            $purchase->refund_reason = 'Duplicate payment';
            $refundId = $this->purchases->gateway($purchase->gateway)->refund($purchase);
            $purchase->update([
                'status' => 'refunded', 'refunded_at' => now(), 'needs_review' => true, 'gateway_refund_id' => $refundId,
                'failure_code' => 'duplicate_payment',
            ]);
            Log::warning('Duplicate payment refunded', ['purchase_id' => $purchase->id]);

            return 'processed';
        }

        if ($tax !== null && $tax > 0) {
            $purchase->update(['tax_cents' => $tax]);
        }
        $this->purchases->activate($purchase, $paymentId);
        $after[] = fn () => $this->purchases->invoice($purchase->refresh());

        return 'processed';
    }

    /** The attempt is over. Only a pending purchase can fail; anything else is a late or repeated event. */
    private function fail(EventPurchase $purchase, string $code): string
    {
        if ($purchase->status !== 'pending') {
            return 'ignored';
        }

        $purchase->update(['status' => 'failed', 'failure_code' => $code]);

        return 'processed';
    }

    private function flag(EventPurchase $purchase, string $code): string
    {
        $purchase->update(['needs_review' => true, 'failure_code' => $purchase->failure_code ?? $code]);
        Log::warning('Payment event does not match its purchase', ['purchase_id' => $purchase->id, 'code' => $code]);

        return 'mismatch';
    }

    /** A refund made in the provider's dashboard (ours are already recorded: the purchase is no longer `paid`). */
    private function externalRefund(EventPurchase $purchase, GatewayEvent $event, array &$after, ?string $refundId = null): string
    {
        if ($purchase->status !== 'paid' || $purchase->gateway_refund_id) {
            return 'ignored';
        }
        if (($event->amountRefunded ?? 0) < (int) ($event->amount ?? $purchase->amount_cents)) {
            $purchase->update(['needs_review' => true]); // partial refunds are not modelled

            return 'processed';
        }

        $this->reverse($purchase, 'refunded', 'Refunded from the payment dashboard', $after);
        $purchase->update(['refunded_at' => now(), 'refund_reason' => 'Refunded from the payment dashboard', 'gateway_refund_id' => $refundId ?? $purchase->gateway_refund_id]);

        return 'processed';
    }

    private function disputeCreated(EventPurchase $purchase, GatewayEvent $event): string
    {
        // The plan stays active during the dispute; a person decides.
        $purchase->update([
            'dispute_id' => $event->disputeId, 'dispute_status' => $event->disputeStatus, 'disputed_at' => $purchase->disputed_at ?? now(),
            'needs_review' => true,
        ]);

        return 'processed';
    }

    private function disputeClosed(EventPurchase $purchase, GatewayEvent $event, array &$after): string
    {
        $purchase->update(['dispute_id' => $event->disputeId ?? $purchase->dispute_id, 'dispute_status' => $event->disputeStatus]);

        if ($event->disputeStatus === 'won') {
            $purchase->update(['needs_review' => false]);
        } elseif ($event->disputeStatus === 'lost' && $purchase->status === 'paid') {
            $this->reverse($purchase, 'chargeback', 'Chargeback lost', $after);
        }

        return 'processed';
    }

    /**
     * The money is gone: credit note, and the event goes back to the free plan unless it is published (destructive:
     * a person decides, so the purchase is flagged instead).
     */
    private function reverse(EventPurchase $purchase, string $status, string $reason, array &$after): void
    {
        $purchase->update(['status' => $status, 'chargeback_at' => $status === 'chargeback' ? now() : $purchase->chargeback_at]);

        $note = app(InvoiceService::class)->creditForRefund($purchase, $reason);
        if ($note) {
            $after[] = fn () => app(InvoiceService::class)->deliverCreditNote($note);
        }

        if ($purchase->event->status === 'published') {
            $purchase->update(['needs_review' => true]);
        } else {
            $purchase->event()->update(['event_plan_id' => EventPlan::query()->where('slug', PurchaseService::FREE_SLUG)->value('id')]);
            // A query-builder write: no model event, so the public invitation is renewed by hand.
            InvitationCache::flushEvent($purchase->event_id);
        }
    }

    /**
     * Ask the provider about a pending purchase and apply the answer: a delayed or lost webhook must not leave the buyer
     * waiting. `$force` skips the 15 s limit (the scheduled command).
     */
    public function reconcile(EventPurchase $purchase, bool $force = false): EventPurchase
    {
        if ($purchase->gateway !== 'stripe' || $purchase->status !== 'pending' || ! $purchase->gateway_session_id) {
            return $purchase;
        }
        if (! $force && ! Cache::add("purchase-reconcile:{$purchase->id}", 1, self::RECONCILE_EVERY_SECONDS)) {
            return $purchase;
        }

        try {
            $session = $this->purchases->gateway($purchase->gateway)->retrieveSession($purchase->gateway_session_id);
        } catch (PaymentGatewayException $e) {
            Log::warning('Reconciliation could not reach the gateway', ['purchase_id' => $purchase->id, 'error' => $e->getMessage()]);

            return $purchase;
        }

        $after = [];
        DB::transaction(function () use ($purchase, $session, &$after) {
            $locked = EventPurchase::query()->lockForUpdate()->find($purchase->id);
            if (! $locked || $locked->status !== 'pending') {
                return;
            }

            if ($session['state'] === 'complete' && $session['paymentStatus'] === 'paid') {
                $this->paid($locked, $session['paymentId'], $session['amount'], $session['currency'], $after, $session['tax'] ?? null);
            } elseif ($session['state'] === 'expired') {
                $this->fail($locked, 'expired');
            }
        });

        foreach ($after as $callback) {
            $callback();
        }

        return $purchase->refresh();
    }

    /**
     * Admin "sync": ask the provider about everything that can have happened (session, refunds, dispute) and apply what
     * is missing, with the same forward-only rules as the webhook.
     *
     * @throws PaymentGatewayException when the provider cannot be reached
     */
    public function sync(EventPurchase $purchase): EventPurchase
    {
        $gateway = $this->purchases->gateway($purchase->gateway);

        $session = $purchase->gateway_session_id ? $gateway->retrieveSession($purchase->gateway_session_id) : null;
        $paymentId = $purchase->gateway_payment_id ?? ($session['paymentId'] ?? null);
        $payment = $paymentId ? $gateway->retrievePayment($paymentId) : null;

        $after = [];
        DB::transaction(function () use ($purchase, $session, $paymentId, $payment, &$after) {
            $p = EventPurchase::query()->lockForUpdate()->findOrFail($purchase->id);

            // A payment the webhook never told us about (also a late one on a session we had already given up on).
            if ($session && in_array($p->status, ['pending', 'failed'], true)) {
                if ($session['state'] === 'complete' && $session['paymentStatus'] === 'paid') {
                    $this->paid($p, $session['paymentId'], $session['amount'], $session['currency'], $after, $session['tax'] ?? null);
                } elseif ($session['state'] === 'expired' && $p->status === 'pending') {
                    $this->fail($p, 'expired');
                }
                $p->refresh();
            }

            if (! $payment || $p->status !== 'paid') {
                return;
            }

            if ($payment['disputeId']) {
                $this->disputeSeen($p, $payment['disputeId'], $payment['disputeStatus'], $after);
                $p->refresh();
            }
            if ($payment['amountRefunded'] > 0 && $p->status === 'paid') {
                $this->externalRefund($p, new GatewayEvent(
                    id: 'sync', type: 'sync', kind: GatewayEvent::REFUNDED, paymentId: $paymentId,
                    amount: (int) $p->amount_cents, amountRefunded: $payment['amountRefunded'],
                ), $after, $payment['refundId']);
            }
        });

        foreach ($after as $callback) {
            $callback();
        }

        return $purchase->refresh();
    }

    /** A dispute the provider reports (webhook or sync): creates the mark if new, then applies its status. */
    private function disputeSeen(EventPurchase $purchase, string $disputeId, ?string $status, array &$after): void
    {
        if ($purchase->dispute_id !== $disputeId) {
            $this->disputeCreated($purchase, new GatewayEvent(id: 'sync', type: 'sync', kind: GatewayEvent::DISPUTE_CREATED, disputeId: $disputeId, disputeStatus: $status));
        }
        if (in_array($status, ['won', 'lost'], true)) {
            $this->disputeClosed($purchase, new GatewayEvent(id: 'sync', type: 'sync', kind: GatewayEvent::DISPUTE_CLOSED, disputeId: $disputeId, disputeStatus: $status), $after);
        } else {
            $purchase->update(['dispute_status' => $status]);
        }
    }

    /**
     * The buyer gave up. Closes the session; if the provider says it was paid in the meantime, nothing is cancelled.
     *
     * @throws PaymentGatewayException when the session could not be closed
     */
    public function cancel(EventPurchase $purchase): EventPurchase
    {
        if ($purchase->gateway_session_id && $purchase->gateway === 'stripe') {
            $gateway = $this->purchases->gateway($purchase->gateway);
            try {
                $gateway->expireSession($purchase->gateway_session_id);
            } catch (PaymentGatewayException $e) {
                // Most likely it is no longer open (paid or expired): see what happened before deciding.
                $purchase = $this->reconcile($purchase, force: true);
                if ($purchase->status === 'pending') {
                    throw $e;
                }
            }
        }

        DB::transaction(function () use ($purchase) {
            $locked = EventPurchase::query()->lockForUpdate()->findOrFail($purchase->id);
            if ($locked->status === 'pending') {
                $locked->update(['status' => 'failed', 'failure_code' => 'cancelled']);
            }
        });

        return $purchase->refresh();
    }
}
