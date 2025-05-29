<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\app\StoreEventsRequest;
use App\Http\Requests\app\UpdateEventsRequest;
use App\Http\Resources\AppResources\EventPlansResource;
use App\Http\Resources\AppResources\EventResource;
use App\Http\Resources\AppResources\EventTypesResource;
use App\Http\Services\AppServices\EventsServices;
use App\Models\Events;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;
use Throwable;

class EventsController extends Controller
{
    public function __construct(private readonly EventsServices $eventsServices) {}

    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse|AnonymousResourceCollection
    {
        try {
            [$events, $lastActiveEvent] = $this->eventsServices->getUserEvents();
            
            return response()->json([
                'message' => 'Events retrieved successfully.',
                'data' => [
                    'events' => EventResource::collection($events),
                    'last_active_event' => $lastActiveEvent ? EventResource::make($lastActiveEvent) : null,
                ]
            ]);
        } catch (Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => [
                'trace' => $th->getTraceAsString()
            ]], 500);
        }
    }
    
    /**
     * Set the active event for the user.
     * @param Request $request
     * @return JsonResponse
     */
    public function activeEvent(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $event = Events::query()
                ->where('id', $request->input('eventId'))
                ->first();
            
            if (!$event) {
                return response()->json(['message' => 'Event not found 123.'], 404);
            }
            
            $user->last_active_event_id = $event->id;
            $user->save();
            
            return response()->json([
                'message' => 'Event activated successfully.',
                'data' => EventResource::make($event)
            ]);
        } catch (Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }
    
    
    
    /**
     * Filtering events.
     * @param Request $request
     * @return JsonResponse|AnonymousResourceCollection
     */
    public function filterEvents(Request $request): JsonResponse|AnonymousResourceCollection
    {
        try {
            return EventResource::collection($this->eventsServices->getFilteredEvents(
                $request->input('query') ?? ''));
        } catch (Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }

    /**
     * Store user event.
     * @param StoreeventsRequest $request
     * @return JsonResponse|EventResource
     */
    public function store(StoreEventsRequest $request): JsonResponse|EventResource
    {
        try {
            return EventResource::make($this->eventsServices->create());
        } catch (Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Events $event): JsonResponse|EventResource
    {
        try {
            return EventResource::make($event);
        } catch (Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateEventsRequest $request, Events $event): JsonResponse|EventResource
    {
        try {
            return EventResource::make($this->eventsServices->update($event));
        } catch (Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Events $event): JsonResponse|EventResource
    {
        try {
            return response()->json([
                'message' => 'Event deleted successfully.',
                'data' => $this->eventsServices->destroy($event)
            ]);
        } catch (Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }
    
    /**
     * Retrieve event types and loan plans.
     * @return JsonResponse
     */
    public function loanEventsPlansAndType(): JsonResponse
    {
        try {
            $eventTypes = $this->eventsServices->getEventTypes();
            $eventPlans = $this->eventsServices->getEventPlans();
            
            return response()->json([
                'message' => 'Event types and loan plans retrieved successfully.',
                'data' => [
                    'eventTypes' =>  EventTypesResource::collection($eventTypes),
                    'eventPlans' => EventPlansResource::collection($eventPlans),
                ]
            ]);
        } catch (Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }
    
    /**
     * Retrieve event suggestions.
     * @param Events $event
     * @return JsonResponse
     */
    public function suggestions(Events $event): JsonResponse
    {
        try {
            $suggestions = $this->eventsServices->getEventSuggestions($event);
            
            return response()->json($suggestions);
        } catch (Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }
    
}
