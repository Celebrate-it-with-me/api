<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\app\StoreGuestRequest;
use App\Http\Resources\AppResources\GuestResource;
use App\Http\Services\AppServices\GuestServices;
use App\Models\Events;
use App\Models\Guest;
use Illuminate\Http\JsonResponse;
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
    
    /**
     * Remove a guest from the event.
     * @param Events $event
     * @param Guest $guest
     * @return JsonResponse
     */
    public function destroy(Events $event, Guest $guest): JsonResponse
    {
        try {
            $this->guestServices->delete($guest);
            return response()->json(['message' => 'Guest deleted successfully', 'data' => []], 200);
        } catch (Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }
    
    
    /**
     * Show the event guest and all the relations.
     * @param Events $event
     * @param Guest $guest
     * @return JsonResponse|GuestResource
     */
    public function show(Events $event, Guest $guest): JsonResponse | GuestResource
    {
        try {
            return GuestResource::make($this->guestServices->showGuest($guest));
        } catch (Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }
}
