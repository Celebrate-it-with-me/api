<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\app\StoreRsvpRequest;
use App\Http\Resources\AppResources\GuestResource;
use App\Http\Resources\AppResources\RsvpResource;
use App\Http\Services\AppServices\RsvpServices;
use App\Models\Events;
use App\Models\Guest;
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
    
    /**
     * Revert the RSVP confirmation status of a guest and their companions.
     * @param Request $request
     * @param Events $event
     * @param Guest $guest
     * @return JsonResponse
     */
    public function revertConfirmation(Request $request, Events $event, Guest $guest): JsonResponse
    {
        try {
            $guest->update([
                'rsvp_status' => 'pending',
                'rsvp_status_date' => null,
            ]);
            
            $guest->selectedMenuItems()->detach();
            
            if ($guest->companions) {
                foreach ($guest->companions as $companion) {
                    $companion->update([
                        'rsvp_status' => 'pending',
                        'rsvp_status_date' => null,
                    ]);
                    
                    $companion->selectedMenuItems()->detach();
                }
            }
            
            
            
            return response()->json(['message' => 'Rsvp reverted.']);
        } catch (\Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }
    
    /**
     * Retrieve RSVP summary for a specific event.
     * @param Events $event
     * @return JsonResponse
     */
    public function summary(Events $event): JsonResponse
    {
        try {
            return response()->json($this->rsvpService->summary($event));
        } catch (\Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }
    
    /**
     * Retrieve a list of RSVP users for a specific event.
     * Filters can be applied based on RSVP status and search value.
     * Supports pagination.
     */
    public function getRsvpUsersList(Request $request, Events $event)
    {
        try {
            $guests = $this->rsvpService->getRsvpGuests($event);
            
            if (!$guests->count()) {
                return response()->json(['message' => 'There are no guests for this event.'], 200);
            }
            
            return GuestResource::collection($guests)
                ->response()->getData(true);
                
        } catch (\Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }
    
    /**
     * Retrieve RSVP user totals for a specific event.
     */
    public function getRsvpUsersTotals(Request $request, Events $event)
    {
        try {
            $totalGuests = Guest::query()
                ->where('event_id', $event->id)
                ->count();
            
            $totalMainGuests = Guest::query()
                ->where('event_id', $event->id)
                ->whereNull('parent_id')
                ->count();
            $totalCompanions = Guest::query()
                ->where('event_id', $event->id)
                ->whereNotNull('parent_id')
                ->count();
            
            $totalPending = Guest::query()
                ->where('event_id', $event->id)
                ->where('rsvp_status', 'pending')
                ->count();
            
            $totalConfirmed = Guest::query()
                ->where('event_id', $event->id)
                ->where('rsvp_status', 'attending')
                ->count();
            
            $totalDeclined = Guest::query()
                ->where('event_id', $event->id)
                ->where('rsvp_status', 'not-attending')
                ->count();
            
            return response()->json([
                'message' => 'Rsvp totals retrieved.',
                'data' => [
                    'totalGuests' => $totalGuests,
                    'totalMainGuests' => $totalMainGuests,
                    'totalCompanions' => $totalCompanions,
                    'totalPending' => $totalPending,
                    'totalConfirmed' => $totalConfirmed,
                    'totalDeclined' => $totalDeclined,
                ],
            ]);
        } catch (\Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }
    
}
