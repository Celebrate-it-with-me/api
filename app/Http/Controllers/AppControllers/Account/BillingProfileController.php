<?php

namespace App\Http\Controllers\AppControllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\BillingProfileRequest;
use App\Models\BillingProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The logged-in organizer's billing data. There is no id in the URL: the row is always the one of the authenticated
 * user, so there is nothing to reach for somebody else's. The data is copied onto an invoice only when it is issued;
 * saving it later never changes an invoice that already exists (they are immutable).
 */
class BillingProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $profile = BillingProfile::query()->where('user_id', $request->user()->id)->first();

        return response()->json(['data' => $this->payload($profile, $request->user()->email)]);
    }

    public function update(BillingProfileRequest $request): JsonResponse
    {
        $d = $request->validated();
        $profile = BillingProfile::query()->updateOrCreate(['user_id' => $request->user()->id], [
            'name' => $d['name'], 'billing_email' => $d['billingEmail'], 'address' => $d['address'] ?? null, 'city' => $d['city'] ?? null,
            'state' => $d['state'] ?? null, 'postal_code' => $d['postalCode'] ?? null, 'country' => $d['country'], 'tax_id' => $d['taxId'] ?? null,
        ]);

        return response()->json(['data' => $this->payload($profile, $request->user()->email)]);
    }

    private function payload(?BillingProfile $p, string $accountEmail): array
    {
        return [
            'exists' => $p !== null,
            'name' => $p?->name, 'billingEmail' => $p?->billing_email, 'address' => $p?->address, 'city' => $p?->city,
            'state' => $p?->state, 'postalCode' => $p?->postal_code, 'country' => $p?->country, 'taxId' => $p?->tax_id,
            'accountEmail' => $accountEmail,
        ];
    }
}
