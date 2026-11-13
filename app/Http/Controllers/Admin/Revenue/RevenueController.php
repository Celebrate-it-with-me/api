<?php

namespace App\Http\Controllers\Admin\Revenue;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Revenue\PurchaseIndexRequest;
use App\Http\Services\Admin\Revenue\RevenueDirectory;
use App\Models\EventPurchase;
use Illuminate\Http\JsonResponse;

/** Read-only global view of purchases and pricing. Writes (refund, price, codes) stay in AdminPaymentController. */
class RevenueController extends Controller
{
    public function __construct(private readonly RevenueDirectory $directory) {}

    public function purchases(PurchaseIndexRequest $request): JsonResponse
    {
        return response()->json($this->directory->list($request->validated()));
    }

    public function purchase(EventPurchase $purchase): JsonResponse
    {
        return response()->json(['data' => $this->directory->detail($purchase)]);
    }

    public function pricing(): JsonResponse
    {
        return response()->json(['data' => $this->directory->pricing()]);
    }
}
