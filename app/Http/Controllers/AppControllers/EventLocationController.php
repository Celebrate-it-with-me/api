<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEventLocationRequest;
use App\Http\Requests\UpdateEventLocationRequest;
use App\Http\Resources\AppResources\EventLocationResource;
use App\Models\EventLocation;
use App\Models\Events;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;
use Throwable;

class EventLocationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Events $events): JsonResponse|AnonymousResourceCollection
    {
        try {
            return EventLocationResource::collection(EventLocation::all());
        } catch (Throwable $th) {
            Log::error($th->getMessage());
            return response()->json([
                'error' => 'An error occurred while fetching event locations.',
                'message' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreEventLocationRequest $request, Events $event): JsonResponse | EventLocationResource
    {
        try {
            $eventLocation = EventLocation::query()
                ->create([
                    ...$request->validated(),
                    'event_id' => $event->id,
                ]);
            
            return EventLocationResource::make($eventLocation);
        } catch (Throwable $th) {
            Log::error($th->getMessage());
            return response()->json([
                'error' => 'An error occurred while creating event location.',
                'message' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(EventLocation $eventLocation): JsonResponse|EventLocationResource
    {
        try {
            return EventLocationResource::make($eventLocation);
        } catch (Throwable $th) {
            Log::error($th->getMessage());
            return response()->json([
                'error' => 'An error occurred while fetching event location.',
                'message' => $th->getMessage(),
            ], 500);
        }
    }


    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateEventLocationRequest $request, Events $event, EventLocation $eventLocation)
    {
        try {
            $eventLocation->update($request->validated());
            return EventLocationResource::make($eventLocation);
        } catch (Throwable $th) {
            Log::error($th->getMessage());
            return response()->json([
                'error' => 'An error occurred while updating event location.',
                'message' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(EventLocation $eventLocation)
    {
        try {
            $eventLocation->delete();
            return response()->json([
                'message' => 'Event location deleted successfully.',
            ], 200);
        } catch (Throwable $th) {
            Log::error($th->getMessage());
            return response()->json([
                'error' => 'An error occurred while deleting event location.',
                'message' => $th->getMessage(),
            ], 500);
        }
    }
}
