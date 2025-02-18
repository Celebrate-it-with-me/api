<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\app\StoreRsvpRequest;
use App\Http\Requests\app\UpdateRsvpRequest;
use App\Http\Resources\AppResources\RsvpResource;
use App\Http\Services\AppServices\RsvpServices;
use App\Models\Events;
use App\Models\Rsvp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RsvpController extends Controller
{
    private RsvpServices  $rsvpService;
    
    public function __construct(RsvpServices $rsvpService)
    {
        $this->rsvpService = $rsvpService;
    }
    
    
    /**
     * Display a listing of the resource.
     */
    public function index(Events $event): JsonResponse|RsvpResource
    {
        try {
            return $event->rsvp
                ? RsvpResource::make($event->rsvp)
                : response()->json(['message' => 'There is no rsvp for this event.', 'data' => []]);
        } catch (\Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }
    
    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreRsvpRequest $request, Events $event): JsonResponse|RsvpResource
    {
        try {
            return RsvpResource::make($this->rsvpService->create($event));
        } catch (\Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }
    
    /**
     * Save the RSVP data.
     */
    public function saveRsvp(Request $request): JsonResponse|bool
    {
        try {
            $this->rsvpService->saveRsvp();
            
            return response()->json(['message' => 'Rsvp saved.']);
        } catch (\Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }
    
}
