<?php

namespace App\Http\Services\AppServices\Hydrate\DTOs;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;

class HydrationData
{
    public function __construct(
        public Collection|AnonymousResourceCollection|null $events = null,
        public mixed $activeEvent = null,
        public ?Collection $menus = null,
        public mixed $eventFeatures = null,
        public ?array $guests = null,
        public ?array $rsvp = null,
        public mixed $saveTheDate = null,
        public ?array $menuGuests = null,
        public mixed $location = null,
        public ?array $comments = null,
        public ?array $userPermissions = null,
        public Collection $eventTypes = new Collection(),
        public Collection $eventPlans = new Collection(),
    ) {}

    /**
     * Converts the current object properties into an associative array.
     *
     * @return array An associative array representation of the object's properties.
     */
    public function toArray(): array
    {
        return [
            'events' => $this->events,
            'activeEvent' => $this->activeEvent,
            'menus' => $this->menus,
            'eventFeatures' => $this->eventFeatures,
            'guests' => $this->guests,
            'rsvp' => $this->rsvp,
            'saveTheDate' => $this->saveTheDate,
            'menuGuests' => $this->menuGuests,
            'location' => $this->location,
            'comments' => $this->comments,
            'userPermissions' => $this->userPermissions,
            'eventTypes' => $this->eventTypes,
            'eventPlans' => $this->eventPlans,
        ];
    }

    /**
     * Creates an empty instance of the class with optionally provided collections.
     *
     * @param Collection|null $events Optional collection of events. Defaults to null.
     * @param Collection $eventTypes Collection of event types. Defaults to an empty collection.
     * @param Collection $eventPlans Collection of event plans. Defaults to an empty collection.
     * @return self Returns a new instance of the class.
     */
    public static function empty(
        ?Collection $events = null,
        Collection $eventTypes = new Collection(),
        Collection $eventPlans = new Collection(),
    ): self {
        return new self(
            events: $events,
            eventTypes: $eventTypes,
            eventPlans: $eventPlans,
        );
    }
}
