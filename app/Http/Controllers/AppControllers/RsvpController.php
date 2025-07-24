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
     *
     * @param Request $request The HTTP request
     * @param Events $event The event
     * @param Guest $guest The guest to revert
     * @return JsonResponse Response with status message
     */
    public function revertConfirmation(Request $request, Events $event, Guest $guest): JsonResponse
    {
        try {
            // This method contains business logic that could be moved to the service layer in a future refactoring
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
     *
     * @param Request $request The HTTP request
     * @param Events $event The event to get RSVP users for
     */
    public function getRsvpUsersList(Request $request, Events $event): JsonResponse|array
    {
        try {
            $perPage = $request->input('perPage', 15);
            $requestStatus = $request->input('status');
            $search = $request->input('search');

            return $this->rsvpService->getUsersList($event, $perPage, $requestStatus, $search);
        } catch (\Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }

    /**
     * Retrieve RSVP user totals for a specific event.
     *
     * @param Request $request The HTTP request
     * @param Events $event The event to get totals for
     * @return \Illuminate\Http\JsonResponse Totals data
     */
    public function getRsvpUsersTotals(Request $request, Events $event)
    {
        try {
            return response()->json($this->rsvpService->getUsersTotals($event));
        } catch (\Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }

}
