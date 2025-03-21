<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\app\StoreGuestRequest;
use App\Http\Resources\AppResources\GuestResource;
use App\Http\Services\AppServices\GuestServices;
use App\Models\Events;
use App\Models\MainGuest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class GuestController extends Controller
{
    public function __construct(private readonly GuestServices $guestServices) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Events $event)
    {
        try {
            return GuestResource::collection($this->guestServices->getEventsGuests($event))
                ->response()->getData(true);
        } catch (Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }
    
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
        } catch (Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }
    
    /**
     * Update the companion type for a main guest.
     * @param Request $request
     * @param MainGuest $guest
     * @return GuestResource|JsonResponse
     */
    public function updateCompanion(Request $request, MainGuest $guest): GuestResource|JsonResponse
    {
        try {
            return GuestResource::make($this->guestServices->updateCompanionType($guest, $request));
        } catch (Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }
}
