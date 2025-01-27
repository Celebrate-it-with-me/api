<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\app\StoreEventsRequest;
use App\Http\Requests\app\UpdateEventsRequest;
use App\Http\Resources\AppResources\EventResource;
use App\Http\Services\AppServices\EventsServices;
use App\Models\Events;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
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
            return EventResource::make($this->eventsServices->destroy($event));
        } catch (Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }
}
