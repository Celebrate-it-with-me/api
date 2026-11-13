<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Http\Services\Payments\FakeGateway;
use App\Http\Services\Payments\PurchaseService;
use App\Models\EventPurchase;
use App\Models\Events;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseController extends Controller
{
    public function __construct(private readonly PurchaseService $purchases) {}

    /** Price (with an optional code) and whether the event is already unlocked. */
    public function quote(Request $request, Events $event): JsonResponse
    {
        $data = $request->validate(['code' => ['nullable', 'string', 'max:64']]);

        return response()->json(['data' => $this->purchases->quote($event, $data['code'] ?? null)]);
    }

    public function checkout(Request $request, Events $event): JsonResponse
    {
        $data = $request->validate(['code' => ['nullable', 'string', 'max:64']]);
        $front = rtrim((string) config('app.frontend_app.url'), '/') . "/dashboard/events/{$event->id}/unlock";

        $result = $this->purchases->checkout($event, $request->user(), $data['code'] ?? null, $front . '?paid=1', $front . '?cancelled=1');

        return response()->json(['data' => ['purchaseId' => $result['purchase']->id, 'url' => $result['url']]], 201);
    }

    /** The screen polls this while it waits for the webhook. */
    public function status(Events $event): JsonResponse
    {
        $last = EventPurchase::query()->where('event_id', $event->id)->latest('id')->first();

        return response()->json(['data' => [
            'unlocked' => $this->purchases->isUnlocked($event),
            'status' => $last?->status,
        ]]);
    }

    /** Called by the payment provider. Signed; idempotent. (Stripe's own signature check replaces this one.) */
    public function webhook(Request $request): JsonResponse
    {
        $payload = $request->getContent();

        if (! hash_equals(FakeGateway::sign($payload), (string) $request->header('X-Signature'))) {
            return response()->json(['message' => 'Invalid signature.'], 400);
        }

        $data = json_decode($payload, true) ?: [];
        $purchase = EventPurchase::query()->where('gateway_session_id', $data['session'] ?? '')->first();

        if ($purchase && ($data['type'] ?? null) === 'payment.succeeded') {
            $this->purchases->markPaid($purchase, $data['payment'] ?? null);
        }

        return response()->json(['received' => true]);
    }
}
