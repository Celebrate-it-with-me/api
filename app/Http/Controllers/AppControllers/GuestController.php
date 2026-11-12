<?php

namespace App\Http\Controllers\AppControllers;

use App\Exceptions\PlanLimitExceeded;
use App\Http\Controllers\Controller;
use App\Http\Requests\app\StoreGuestRequest;
use App\Http\Resources\AppResources\GuestResource;
use App\Http\Services\AppServices\GuestServices;
use App\Models\Events;
use Illuminate\Http\JsonResponse;
use Throwable;

class GuestController extends Controller
{
    public function __construct(private readonly GuestServices $guestServices) {}

    /**
     * Store event guest.
     * @param StoreGuestRequest $request
     * @param Events $event
     * @return JsonResponse|GuestResource
     */
    public function store(StoreGuestRequest $request, Events $event): JsonResponse|GuestResource
    {
        try {
            return GuestResource::make($this->guestServices->create($event));
        } catch (PlanLimitExceeded $limit) {
            throw $limit; // renders as a 402 with the numbers (see the exception)
        } catch (Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }


}
