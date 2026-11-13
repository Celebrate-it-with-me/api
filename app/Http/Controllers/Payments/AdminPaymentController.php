<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Http\Services\Payments\PurchaseService;
use App\Models\Coupon;
use App\Models\EventPlan;
use App\Models\EventPurchase;
use App\Models\Events;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminPaymentController extends Controller
{
    public function __construct(private readonly PurchaseService $purchases) {}

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
        ]);
        $buyer = isset($data['userId']) ? User::findOrFail($data['userId']) : $request->user();

        return response()->json(['data' => $this->purchases->recordManual($event, $buyer, $request->user(), $data['amountCents'], $data['notes'] ?? null)], 201);
    }

    public function refund(EventPurchase $purchase): JsonResponse
    {
        return response()->json(['data' => $this->purchases->refund($purchase)]);
    }

    public function updatePrice(Request $request): JsonResponse
    {
        $data = $request->validate(['priceCents' => ['required', 'integer', 'min:0', 'max:1000000']]);
        $plan = $this->purchases->fullPlan();
        $plan->update(['price_cents' => $data['priceCents']]);

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
        ]);

        $coupon = Coupon::create([
            'code' => strtoupper(trim($data['code'])), 'kind' => $data['kind'], 'value' => $data['value'],
            'is_founder' => $data['isFounder'] ?? false, 'max_uses' => $data['maxUses'] ?? null,
            'expires_at' => $data['expiresAt'] ?? null, 'notes' => $data['notes'] ?? null,
        ]);

        return response()->json(['data' => $coupon], 201);
    }

    public function updateCoupon(Request $request, Coupon $coupon): JsonResponse
    {
        $data = $request->validate(['isActive' => ['boolean'], 'maxUses' => ['nullable', 'integer', 'min:1'], 'expiresAt' => ['nullable', 'date']]);
        $coupon->update(array_filter([
            'is_active' => $data['isActive'] ?? null, 'max_uses' => $data['maxUses'] ?? null, 'expires_at' => $data['expiresAt'] ?? null,
        ], fn ($v) => $v !== null));

        return response()->json(['data' => $coupon]);
    }
}
