<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Http\Services\Payments\InvalidWebhookException;
use App\Http\Services\Payments\PaymentSettlement;
use App\Http\Services\Payments\PurchaseService;
use App\Http\Services\Payments\WebhookNotConfiguredException;
use App\Models\EventPurchase;
use App\Models\Events;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseController extends Controller
{
    public function __construct(private readonly PurchaseService $purchases, private readonly PaymentSettlement $settlement) {}

    /** Price (with an optional code) and whether the event is already unlocked. */
    public function quote(Request $request, Events $event): JsonResponse
    {
        $data = $request->validate(['code' => ['nullable', 'string', 'max:64']]);

        return response()->json(['data' => $this->purchases->quote($event, $data['code'] ?? null, $request->user())]);
    }

    public function checkout(Request $request, Events $event): JsonResponse
    {
        // `expectedAmountCents`: the price the screen showed; if it is no longer that, nothing is created (409 price_changed).
        $data = $request->validate(['code' => ['nullable', 'string', 'max:64'], 'expectedAmountCents' => ['nullable', 'integer', 'min:0']]);
        $front = rtrim((string) config('app.frontend_app.url'), '/') . "/dashboard/events/{$event->id}/unlock";
        $locale = $request->getPreferredLanguage(['es', 'en']) ?? 'es';

        $result = $this->purchases->checkout(
            $event, $request->user(), $data['code'] ?? null, $front . '?paid=1', $front . '?cancelled=1', ['locale' => $locale], isset($data['expectedAmountCents']) ? (int) $data['expectedAmountCents'] : null,
        );

        return response()->json(['data' => ['purchaseId' => $result['purchase']->id, 'url' => $result['url']]], 201);
    }

    /**
     * The screen polls this while it waits for the webhook. `?purchase=` asks about one purchase of this event; a pending
     * Stripe purchase older than 20 s is reconciled with the provider (at most once every 15 s).
     */
    public function status(Request $request, Events $event): JsonResponse
    {
        $request->validate(['purchase' => ['nullable', 'integer']]);

        $query = EventPurchase::query()->where('event_id', $event->id);
        $purchase = $request->filled('purchase') ? $query->findOrFail((int) $request->query('purchase')) : $query->latest('id')->first();

        if ($purchase && $purchase->status === 'pending' && $purchase->created_at->lte(now()->subSeconds(20))) {
            $purchase = $this->settlement->reconcile($purchase);
        }

        $invoice = $purchase ? Invoice::query()->where('purchase_id', $purchase->id)->where('status', 'issued')->value('id') : null;

        return response()->json(['data' => [
            'unlocked' => $this->purchases->isUnlocked($event->refresh()),
            'status' => $purchase?->status,
            'purchaseId' => $purchase?->id,
            'failureCode' => $purchase?->failure_code,
            'amountCents' => $purchase?->amount_cents,
            'currency' => $purchase?->currency,
            'invoiceId' => $invoice,
            'offer' => $this->purchases->offerSummary($this->purchases->fullPlan(), $event, null, $request->user()),
        ]]);
    }

    /** The buyer came back from the payment page without paying. */
    public function cancel(Events $event, int $purchase): JsonResponse
    {
        // Looked up inside this event (after the owner check), so another event's purchase is a plain 404.
        $purchase = EventPurchase::query()->where('event_id', $event->id)->findOrFail($purchase);

        if ($purchase->status !== 'pending') {
            return response()->json(['message' => 'The purchase is no longer pending.', 'code' => 'not_pending'], 409);
        }

        $purchase = $this->settlement->cancel($purchase);

        // Paid while she was closing the page: that is not a cancellation.
        if ($purchase->status !== 'failed') {
            return response()->json(['message' => 'The purchase is no longer pending.', 'code' => 'not_pending'], 409);
        }

        return response()->json(['data' => ['status' => $purchase->status, 'failureCode' => $purchase->failure_code]]);
    }

    /** Called by the payment provider: verified by the active gateway over the raw body, idempotent by event id. */
    public function webhook(Request $request): JsonResponse
    {
        $gateway = $this->purchases->gateway();

        try {
            $event = $gateway->parseWebhook($request);
        } catch (WebhookNotConfiguredException) {
            return response()->json(['message' => 'Webhook is not configured.'], 503);
        } catch (InvalidWebhookException) {
            return response()->json(['message' => 'Invalid signature.'], 400);
        }

        // 200 for everything we understood, even an amount that does not match (flagged, not retried); a 5xx only
        // when something of ours failed, so the provider tries again.
        $this->settlement->handle($gateway->name(), $event);

        return response()->json(['received' => true]);
    }
}
