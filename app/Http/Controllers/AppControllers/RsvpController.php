<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\app\StoreRsvpRequest;
use App\Http\Resources\AppResources\RsvpResource;
use App\Http\Services\AppServices\RsvpServices;
use App\Models\Events;
use App\Models\Guest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class RsvpController extends Controller
{
    private RsvpServices  $rsvpService;

    public function __construct(RsvpServices $rsvpService)
    {
        $this->rsvpService = $rsvpService;
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
            Cache::forget("event.{$event->id}.rsvp.stats");
            Cache::forget("event.{$event->id}.rsvp.summary");

            return response()->json(['message' => 'Rsvp reverted.']);
        } catch (\Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }

    /**
     * Retrieve RSVP statistics for a given event.
     *
     * @param Events $event The event for which to retrieve RSVP statistics.
     * @return JsonResponse The JSON response containing the RSVP statistics.
     */
    public function getRsvpStats(Events $event): JsonResponse
    {
        $stats = $this->rsvpService->getEventStatsWithCache($event);

        return response()->json(['message' => 'Rsvp stats retrieved.', 'data' => $stats]);
    }

}
