<?php

namespace App\Http\Controllers\AppControllers\Timeline;

use App\Http\Controllers\Controller;
use App\Http\Requests\app\Timeline\TimelineStoreRequest;
use App\Http\Services\AppServices\Timeline\TimelineServices;
use App\Models\Events;
use App\Models\Timeline;
use Illuminate\Http\JsonResponse;
use Throwable;

class TimelineController extends Controller
{
    public function __construct(private readonly TimelineServices $timelineServices) {}
    
    /**
     * Retrieve timelines associated with the given event and return them as a JSON response.
     *
     * @param Events $event The event object for which timelines are being fetched.
     * @return JsonResponse A JSON response containing a message and the retrieved timelines data.
     */
    public function index(Events $event)
    {
        try {
            $timelines = $this->timelineServices->getTimelinesByEvent($event);
            
            return response()->json([
                'message' => 'Timelines retrieved successfully.',
                'data' => $timelines
            ]);
        } catch (Throwable $th) {
            return response()->json([
                'message' => 'Failed to retrieve timelines.',
                'error' => $th->getMessage()
            ], 500);
        }
    }
    
    public function store(TimelineStoreRequest $request, Events $event)
    {
        try {
            $timelineData = $request->validated();
            $timeline = $this->timelineServices->createTimeline($event, $timelineData);
            
            return response()->json([
                'message' => 'Timeline created successfully.',
                'data' => $timeline
            ]);
        } catch (Throwable $th) {
            return response()->json([
                'message' => 'Failed to create timeline.',
                'error' => $th->getMessage()
            ], 500);
        }
    }
    
    /**
     * Updates the specified timeline for the given event.
     *
     * @param TimelineStoreRequest $request The validated request containing timeline data.
     * @param Events $event The event associated with the timeline being updated.
     * @param Timeline $timeline The timeline instance to be updated.
     *
     * @return JsonResponse Returns a JSON response containing a success message and the updated timeline data on success.
     *                                       Returns a JSON response containing an error message and details in case of failure.
     *
     * @throws Throwable If an exception occurs during the timeline update process.
     */
    public function update(TimelineStoreRequest $request, Events $event, Timeline $timeline)
    {
        try {
            $timelineData = $request->validated();
            $updatedTimeline = $this->timelineServices->updateTimeline($event, $timeline, $timelineData);
            
            return response()->json([
                'message' => 'Timeline updated successfully.',
                'data' => $updatedTimeline
            ]);
        } catch (Throwable $th) {
            return response()->json([
              'message' => 'Failed to update timeline',
              'error' => $th->getMessage()
            ]);
        }
    }
    
    /**
     * Deletes the specified timeline associated with the given event.
     *
     * @param Events $event The event related to the timeline to be deleted.
     * @param Timeline $timeline The timeline instance that needs to be deleted.
     *
     * @return JsonResponse Returns a JSON response with a success message upon successful deletion.
     *                                       Returns a JSON response with an error message and details if the deletion fails.
     *
     * @throws Throwable If an exception occurs during the timeline deletion process.
     */
    public function destroy(Events $event, Timeline $timeline)
    {
        try {
            $this->timelineServices->deleteTimeline($event, $timeline);
            
            return response()->json([
                'message' => 'Timeline deleted successfully.',
            ]);
        } catch (Throwable $th) {
            return response()->json([
                'message' => 'Failed to destroy timeline',
                'error' => $th->getMessage()
            ]);
        }
    }
}
