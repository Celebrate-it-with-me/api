<?php

namespace App\Http\Services\AppServices\Hydrate;

use App\Http\Resources\AppResources\EventResource;
use App\Http\Services\AppServices\Hydrate\DTOs\HydrationData;
use App\Models\Events;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class HydrationResponseBuilder
{
    private ?Collection $events = null;
    private mixed $activeEvent = null;
    private ?array $eventData = null;
    private ?array $userPermissions = null;
    private Collection $eventTypes;
    private Collection $eventPlans;

    /**
     * Constructor for HydrationResponseBuilder.
     *
     * @param EventCacheService $eventCacheService
     */
    public function __construct(
        private readonly EventCacheService $eventCacheService
    )
    {
        $this->eventTypes = $this->eventCacheService->getEventTypes();
        $this->eventPlans = $this->eventCacheService->getEventPlans();
    }

    /**
     * Sets the events for the hydration response.
     *
     * @param Collection|null $events
     * @return $this
     */
    public function withEvents(?Collection $events): self
    {
        $this->events = $events;
        return $this;
    }

    /**
     * Sets the active event for the hydration response.
     *
     * @param Events|null $event
     * @return $this
     */
    public function withActiveEvent(?Events $event): self
    {
        $this->activeEvent = $event;
        return $this;
    }

    /**
     * Sets the event data for the hydration response.
     *
     * @param array|null $eventData
     * @return $this
     */
    public function withEventData(?array $eventData): self
    {
        $this->eventData = $eventData;
        return $this;
    }

    /**
     * Sets the user permissions for the hydration response.
     *
     * @param array|null $permissions
     * @return $this
     */
    public function withUserPermissions(?array $permissions): self
    {
        $this->userPermissions = $permissions;
        return $this;
    }

    public function build(): JsonResponse
    {
        $hydrationData = new HydrationData(
            events: $this->formatEvents(),
            activeEvent: $this->formatActiveEvent(),
            menus: $this->eventData['menus'] ?? null,
            eventFeatures: $this->eventData['eventFeatures'] ?? null,
            guests: $this->eventData['guests'] ?? null,
            rsvp: $this->eventData['rsvp'] ?? null,
            saveTheDate: $this->eventData['saveTheDate'] ?? null,
            menuGuests: $this->eventData['menuGuests'] ?? null,
            location: $this->eventData['location'] ?? null,
            comments: $this->eventData['comments'] ?? null,
            userPermissions: $this->userPermissions,
            eventTypes: $this->eventTypes,
            eventPlans: $this->eventPlans
        );

        return response()->json($hydrationData->toArray());
    }

    /**
     * Builds an empty JSON response containing hydration data.
     *
     * @return JsonResponse A JSON response with formatted events, event types, and event plans.
     */
    public function buildEmpty(): JsonResponse
    {
        $hydrationData = HydrationData::empty(
            events: $this->getEventsCollection(),
            eventTypes: $this->eventTypes,
            eventPlans: $this->eventPlans
        );

        return response()->json($hydrationData->toArray());
    }

    /**
     * Builds a JSON response indicating that the user does not have permission to access the requested resource.
     *
     * @return JsonResponse A JSON response containing the error message and a 403 status code.
     */
    public function buildUnauthorized(): JsonResponse
    {
        return response()->json([
            'message' => 'You do not have permission to access this event. Please contact the event organizer.',
        ], 403);
    }

    /**
     * Formats the events into a collection of event resources.
     *
     * @return AnonymousResourceCollection|null
     */
    private function formatEvents(): ?AnonymousResourceCollection
    {
        if (!$this->events) {
            return null;
        }

        return EventResource::collection($this->events);
    }

    /**
     * Gets the raw events collection.
     *
     * @return Collection|null
     */
    private function getEventsCollection(): ?Collection
    {
        return $this->events;
    }

    /**
     * Formats the active event into a single event resource.
     *
     * @return EventResource|null
     */
    private function formatActiveEvent(): ?EventResource
    {
        if (!$this->activeEvent) {
            return null;
        }

        return EventResource::make($this->activeEvent);
    }

    /**
     * Resets the current object state by clearing its events, active event, event data,
     * and user permissions.
     *
     * @return self Returns the current instance for method chaining.
     */
    public function reset(): self
    {
        $this->events = null;
        $this->activeEvent = null;
        $this->eventData = null;
        $this->userPermissions = null;

        return $this;
    }
}
