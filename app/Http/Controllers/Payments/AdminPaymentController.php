<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Http\Services\Admin\AdminAudit;
use App\Http\Services\Payments\PaymentGatewayException;
use App\Http\Services\Payments\PaymentSettlement;
use App\Http\Services\Payments\PurchaseService;
use App\Models\Coupon;
use App\Models\EventPlan;
use App\Models\EventPurchase;
use App\Models\Events;
use App\Models\EventUserRole;
use App\Models\LaunchOffer;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
            // 10,000 USD: far above the 99 plan, low enough that a typo cannot book a six-figure sale.
            'amountCents' => ['required', 'integer', 'min:0', 'max:1000000'],
            'notes' => ['nullable', 'string', 'max:500'],
            'reason' => AdminAudit::reasonRule(),
        ]);
        $buyer = isset($data['userId']) ? User::findOrFail($data['userId']) : $request->user();

        $beforePlan = $event->eventPlan?->slug;
        // The invoice goes to the buyer: one who is not on the event is allowed but flagged in the audit trail.
        $buyerIsMember = EventUserRole::query()->where('event_id', $event->id)->where('user_id', $buyer->id)->exists();
        $purchase = $this->purchases->recordManual($event, $buyer, $request->user(), $data['amountCents'], $data['notes'] ?? null);

        $this->audit->record('payment.manual', $purchase, [
            'event_id' => $event->id, 'user_id' => $buyer->id, 'reason' => $data['reason'],
            'before' => ['plan' => $beforePlan],
            'after' => ['plan' => $purchase->plan?->slug, 'amountCents' => $purchase->amount_cents, 'gateway' => 'manual']
                + ($buyerIsMember ? [] : ['warning' => 'buyer_not_event_member']),
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

    /** The launch offer as the admin sees it; `data` is null until one exists (the launch seeder or the first PUT creates it). */
    public function launchOffer(): JsonResponse
    {
        $plan = $this->purchases->fullPlan();

        return response()->json(['data' => $this->offerPayload($plan, $this->purchases->launchOffer($plan))]);
    }

    /**
     * Turn the offer on or off and set its price and slots. `slotsUsed` is a correction: the used count is derived from the
     * paid purchases, so the admin states the number she wants and the difference is stored (e.g. a sale made outside
     * the platform that should take a slot). The first PUT creates the offer and needs `priceCents` and `totalSlots`.
     */
    public function updateLaunchOffer(Request $request): JsonResponse
    {
        $plan = $this->purchases->fullPlan();
        $data = $request->validate([
            'isActive' => ['sometimes', 'boolean'],
            // An offer is a discount (below the list price) but not a typo: at least half of it, and never under Stripe's 50 cents.
            'priceCents' => ['sometimes', 'integer', 'min:' . max(PurchaseService::MIN_CHARGE_CENTS, (int) ceil($plan->price_cents / 2)), 'lt:' . max(1, (int) $plan->price_cents)],
            'totalSlots' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'slotsUsed' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            // Lowering the total under what is already used (or setting used above the total) must be said on purpose.
            'allowBelowUsed' => ['sometimes', 'boolean'],
            'reason' => AdminAudit::reasonRule(),
        ]);

        try {
            $offer = DB::transaction(function () use ($plan, $data) {
                $offer = $this->purchases->launchOffer($plan, lock: true);
                if (! $offer && (! isset($data['priceCents']) || ! isset($data['totalSlots']))) {
                    throw ValidationException::withMessages(['priceCents' => ['required_to_create_offer']]);
                }
                $before = $this->offerPayload($plan, $offer);
                $offer ??= new LaunchOffer(['event_plan_id' => $plan->id, 'is_active' => false, 'used_adjustment' => 0]);

                $offer->fill(array_filter([
                    'is_active' => $data['isActive'] ?? null, 'price_cents' => $data['priceCents'] ?? null, 'total_slots' => $data['totalSlots'] ?? null,
                ], fn ($v) => $v !== null));
                if (isset($data['slotsUsed'])) {
                    // The correction only adds (sales made outside the platform); it never hides paid purchases.
                    if ($data['slotsUsed'] < $offer->paidCount()) {
                        throw ValidationException::withMessages(['slotsUsed' => ['below_paid_purchases']]);
                    }
                    $offer->used_adjustment = $data['slotsUsed'] - $offer->paidCount();
                }
                if (! ($data['allowBelowUsed'] ?? false) && (int) $offer->total_slots < $offer->usedSlots()) {
                    throw ValidationException::withMessages(['totalSlots' => ['below_used_slots']]);
                }
                $offer->save();

                return [$offer, $before];
            });
        } catch (UniqueConstraintViolationException) {
            // Two first PUTs at once: the other one created the row. Nothing was changed by this one; try again.
            return response()->json(['message' => 'The launch offer was just created by another request.', 'code' => 'launch_offer_conflict'], 409);
        }
        [$offer, $before] = $offer;

        // LO-006: turning the offer off or changing its price must not leave sessions open at the old price. They are closed
        // here (outside the transaction: it calls the provider); one that cannot be closed stays pending and is reported.
        $closes = ($before['isActive'] ?? false) && (($data['isActive'] ?? true) === false || (isset($data['priceCents']) && $data['priceCents'] !== $before['priceCents']));
        $sessions = $closes ? $this->closeOfferSessions($offer) : ['closed' => 0, 'stillOpen' => 0];

        $after = $this->offerPayload($plan, $offer) + ['sessionsClosed' => $sessions['closed'], 'sessionsStillOpen' => $sessions['stillOpen']];
        $this->audit->record(($data['isActive'] ?? null) === false ? 'launch_offer.deactivate' : 'launch_offer.update', $offer, [
            'reason' => $data['reason'], 'before' => $before, 'after' => $after,
        ]);

        return response()->json(['data' => $after]);
    }

    /** @return array{closed: int, stillOpen: int} */
    private function closeOfferSessions(LaunchOffer $offer): array
    {
        $out = ['closed' => 0, 'stillOpen' => 0];
        foreach ($offer->purchases()->where('status', 'pending')->get() as $purchase) {
            try {
                $this->settlement->cancel($purchase, 'offer_closed')->status === 'failed' ? $out['closed']++ : null;
            } catch (PaymentGatewayException) {
                $out['stillOpen']++;
            }
        }

        return $out;
    }

    private function offerPayload(EventPlan $plan, ?LaunchOffer $offer): ?array
    {
        if (! $offer) {
            return null;
        }

        return [
            'id' => $offer->id,
            'isActive' => (bool) $offer->is_active,
            'active' => $offer->isOpen($plan),
            'priceCents' => (int) $offer->price_cents,
            'listPriceCents' => (int) $plan->price_cents,
            'currency' => $plan->currency,
            'totalSlots' => (int) $offer->total_slots,
            'slotsUsed' => $offer->usedSlots(),
            'slotsPaid' => $offer->paidCount(),
            'slotsAdjustment' => (int) $offer->used_adjustment,
            'slotsHeld' => $offer->heldSlots(),
            'slotsLeft' => $offer->slotsLeft(),
        ];
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
            // F-2: a percentage is 1-100 (never above), a fixed price is zero or more cents.
            'value' => ['required', 'integer', 'min:0', 'max:2147483647', Rule::when($request->input('kind') === 'percent_off', ['between:1,100'])],
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
