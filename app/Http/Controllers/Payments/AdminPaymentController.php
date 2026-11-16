<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Http\Services\Admin\AdminAudit;
use App\Http\Services\Payments\PaymentSettlement;
use App\Http\Services\Payments\PurchaseService;
use App\Models\Coupon;
use App\Models\EventPurchase;
use App\Models\Events;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Permissions are on the routes (`admin.can:`); every write here is in the audit log with its reason. */
class AdminPaymentController extends Controller
{
    public function __construct(private readonly PurchaseService $purchases, private readonly AdminAudit $audit, private readonly PaymentSettlement $settlement) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => EventPurchase::query()->with(['event:id,event_name', 'coupon:id,code'])->latest('id')->limit(200)->get()]);
    }

    public function manual(Request $request, Events $event): JsonResponse
    {
        $data = $request->validate([
            'userId' => ['nullable', 'integer', 'exists:users,id'],
            'amountCents' => ['required', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
            'reason' => AdminAudit::reasonRule(),
        ]);
        $buyer = isset($data['userId']) ? User::findOrFail($data['userId']) : $request->user();

        $beforePlan = $event->eventPlan?->slug;
        $purchase = $this->purchases->recordManual($event, $buyer, $request->user(), $data['amountCents'], $data['notes'] ?? null);

        $this->audit->record('payment.manual', $purchase, [
            'event_id' => $event->id, 'user_id' => $buyer->id, 'reason' => $data['reason'],
            'before' => ['plan' => $beforePlan],
            'after' => ['plan' => $purchase->plan?->slug, 'amountCents' => $purchase->amount_cents, 'gateway' => 'manual'],
        ]);

        return response()->json(['data' => $purchase], 201);
    }

    public function refund(Request $request, EventPurchase $purchase): JsonResponse
    {
        $data = $request->validate(['reason' => AdminAudit::reasonRule()]);
        $refunded = $this->purchases->refund($purchase, $data['reason']);

        $this->audit->record('payment.refund', $refunded, [
            'event_id' => $refunded->event_id, 'user_id' => $refunded->user_id, 'reason' => $data['reason'],
            'before' => ['status' => 'paid', 'amountCents' => $refunded->amount_cents],
            'after' => ['status' => 'refunded', 'gatewayRefundId' => $refunded->gateway_refund_id],
        ]);

        return response()->json(['data' => $refunded]);
    }

    /** Ask Stripe about this purchase and apply what is missing (a lost webhook, a refund or dispute made in its dashboard). */
    public function sync(Request $request, EventPurchase $purchase): JsonResponse
    {
        $data = $request->validate(['reason' => AdminAudit::reasonRule()]);

        if ($purchase->gateway !== 'stripe') {
            return response()->json(['message' => 'Only Stripe purchases can be synced.', 'code' => 'not_stripe'], 409);
        }

        $before = $purchase->only(['status', 'needs_review', 'dispute_status']);
        $synced = $this->settlement->sync($purchase);

        $this->audit->record('payment.sync', $synced, [
            'event_id' => $synced->event_id, 'user_id' => $synced->user_id, 'reason' => $data['reason'],
            'before' => $before, 'after' => $synced->only(['status', 'needs_review', 'dispute_status']),
        ]);

        return response()->json(['data' => ['id' => $synced->id, 'status' => $synced->status, 'needsReview' => (bool) $synced->needs_review, 'disputeStatus' => $synced->dispute_status]]);
    }

    /** A person looked at what the purchase was flagged for. */
    public function resolveReview(Request $request, EventPurchase $purchase): JsonResponse
    {
        $data = $request->validate(['reason' => AdminAudit::reasonRule()]);

        if (! $purchase->needs_review) {
            return response()->json(['message' => 'The purchase is not flagged for review.', 'code' => 'not_needs_review'], 409);
        }

        $purchase->update(['needs_review' => false]);

        $this->audit->record('payment.review.resolve', $purchase, [
            'event_id' => $purchase->event_id, 'user_id' => $purchase->user_id, 'reason' => $data['reason'],
            'before' => ['needsReview' => true], 'after' => ['needsReview' => false],
        ]);

        return response()->json(['data' => ['id' => $purchase->id, 'needsReview' => false]]);
    }

    public function updatePrice(Request $request): JsonResponse
    {
        $data = $request->validate(['priceCents' => ['required', 'integer', 'min:0', 'max:1000000'], 'reason' => AdminAudit::reasonRule()]);
        $plan = $this->purchases->fullPlan();
        $before = $plan->price_cents;
        $plan->update(['price_cents' => $data['priceCents']]);

        $this->audit->record('pricing.update', $plan, ['reason' => $data['reason'], 'before' => ['priceCents' => $before], 'after' => ['priceCents' => $plan->price_cents]]);

        return response()->json(['data' => $plan]);
    }

    public function coupons(): JsonResponse
    {
        return response()->json(['data' => Coupon::query()->latest('id')->get()]);
    }

    public function storeCoupon(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64', Rule::unique('coupons', 'code')],
            'kind' => ['required', Rule::in(['fixed_price', 'percent_off'])],
            'value' => ['required', 'integer', 'min:0'],
            'isFounder' => ['boolean'],
            'maxUses' => ['nullable', 'integer', 'min:1'],
            'expiresAt' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:255'],
            'reason' => AdminAudit::reasonRule(),
        ]);

        $coupon = Coupon::create([
            'code' => strtoupper(trim($data['code'])), 'kind' => $data['kind'], 'value' => $data['value'],
            'is_founder' => $data['isFounder'] ?? false, 'max_uses' => $data['maxUses'] ?? null,
            'expires_at' => $data['expiresAt'] ?? null, 'notes' => $data['notes'] ?? null,
        ]);

        $this->audit->record('coupon.create', $coupon, [
            'reason' => $data['reason'],
            'after' => $coupon->only(['code', 'kind', 'value', 'is_founder', 'max_uses', 'expires_at']),
        ]);

        return response()->json(['data' => $coupon], 201);
    }

    public function updateCoupon(Request $request, Coupon $coupon): JsonResponse
    {
        $data = $request->validate([
            'isActive' => ['boolean'], 'maxUses' => ['nullable', 'integer', 'min:1'], 'expiresAt' => ['nullable', 'date'],
            'reason' => AdminAudit::reasonRule(),
        ]);
        $fields = ['is_active', 'max_uses', 'expires_at'];
        $before = $coupon->only($fields);

        // Absent key = leave as is; explicit null on maxUses/expiresAt = remove the limit.
        $changes = [];
        if (array_key_exists('isActive', $data) && $data['isActive'] !== null) {
            $changes['is_active'] = $data['isActive'];
        }
        if (array_key_exists('maxUses', $data)) {
            $changes['max_uses'] = $data['maxUses'];
        }
        if (array_key_exists('expiresAt', $data)) {
            $changes['expires_at'] = $data['expiresAt'];
        }
        $coupon->update($changes);

        $this->audit->record(($data['isActive'] ?? true) === false ? 'coupon.deactivate' : 'coupon.update', $coupon, [
            'reason' => $data['reason'], 'before' => $before, 'after' => $coupon->only($fields),
        ]);

        return response()->json(['data' => $coupon]);
    }
}
