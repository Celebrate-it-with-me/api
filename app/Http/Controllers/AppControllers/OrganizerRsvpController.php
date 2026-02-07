<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\app\OrganizerConfirmRsvpRequest;
use App\Http\Services\AppServices\OrganizerRsvpServices;
use App\Models\Events;
use App\Models\Guest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class OrganizerRsvpController extends Controller
{
    private OrganizerRsvpServices $organizerRsvpServices;

    public function __construct(OrganizerRsvpServices $organizerRsvpServices)
    {
        $this->organizerRsvpServices = $organizerRsvpServices;
    }

    /**
     * Confirms the RSVP for a given main guest and companions based on the provided request.
     * This is the unified modal approach.
     *
     * @param OrganizerConfirmRsvpRequest $request The request containing the validation data for confirming the RSVP.
     * @param Guest $guest The main guest whose RSVP is to be confirmed.
     * @return JsonResponse
     */
    public function confirmRsvp(OrganizerConfirmRsvpRequest $request, Events $event, Guest $guest): JsonResponse
    {
        try {
            // Verify authorization
            //$this->authorize('manageRsvp', $guest->event);

            // Process confirmation via service
            $result = $this->organizerRsvpServices->processRsvpConfirmation(
                $guest,
                $request->validated()
            );

            return response()->json($result, 200);

        } catch (Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update RSVP confirmations',
                'error' => config('app.debug') ? $th->getMessage() : 'An error occurred'
            ], 500);
        }
    }

    /**
     * Get current RSVP status for a main guest and companions (for modal pre-population)
     *
     * @param Guest $guest The main guest
     * @return JsonResponse
     */
    public function getRsvpStatus(Guest $guest): JsonResponse
    {
        try {
            // Verify authorization
            //$this->authorize('manageRsvp', $guest->event);

            // Get status data via service
            $result = $this->organizerRsvpServices->getRsvpStatusData($guest);

            return response()->json($result, 200);

        } catch (Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get RSVP status',
                'error' => config('app.debug') ? $th->getMessage() : 'An error occurred'
            ], 500);
        }
    }

    /**
     * Bulk apply status to all companions
     *
     * @param Request $request
     * @param Guest $guest The main guest
     * @return JsonResponse
     */
    public function bulkApplyToCompanions(Request $request, Guest $guest): JsonResponse
    {
        $request->validate([
            'rsvp_status' => 'required|in:attending,not_attending,pending',
            'notes' => 'sometimes|string|max:1000'
        ]);

        try {
            // Verify authorization
            //$this->authorize('manageRsvp', $guest->event);

            // Process bulk update via service
            $result = $this->organizerRsvpServices->bulkApplyToCompanions(
                $guest,
                $request->input('rsvp_status'),
                $request->input('notes')
            );

            return response()->json($result, 200);

        } catch (Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to bulk update companions',
                'error' => config('app.debug') ? $th->getMessage() : 'An error occurred'
            ], 500);
        }
    }
}
