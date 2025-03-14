<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\app\StoreEventsRequest;
use App\Http\Requests\app\UpdateEventsRequest;
use App\Http\Resources\AppResources\EventResource;
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
            return EventResource::collection($this->eventsServices->getUserEvents());
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
}
