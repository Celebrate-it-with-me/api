<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\app\StoreGuestRequest;
use App\Http\Resources\AppResources\GuestResource;
use App\Http\Services\AppServices\GuestServices;
use App\Models\Events;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;
use Throwable;

class GuestController extends Controller
{
    public function __construct(private readonly GuestServices $guestServices) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Events $event): JsonResponse|AnonymousResourceCollection
    {
        try {
            return GuestResource::collection($this->guestServices->getEventsGuests($event));
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
}
