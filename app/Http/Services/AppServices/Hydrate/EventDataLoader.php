<?php

namespace App\Http\Services\AppServices\Hydrate;

use App\Http\Resources\AppResources\EventComment\EventCommentResource;
use App\Http\Resources\AppResources\EventFeatureResource;
use App\Http\Resources\AppResources\GuestMenuConfirmationResource;
use App\Http\Resources\AppResources\MenuResource;
use App\Http\Resources\AppResources\SaveTheDateResource;
use App\Http\Services\AppServices\EventModules;
use App\Http\Services\AppServices\GuestServices;
use App\Http\Services\AppServices\LocationsServices;
use App\Http\Services\AppServices\RsvpServices;
use App\Http\Services\EventComment\EventCommentService;
use App\Models\EventLocation;
use App\Models\Events;
use App\Models\Guest;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;

class EventDataLoader
{
    /**
     * Constructor method.
     *
     * @param LocationsServices $locationsServices Instance of LocationsServices.
     * @param GuestServices $guestServices Instance of GuestServices.
     * @param RsvpServices $rsvpServices Instance of RsvpServices.
     *
     * @return void
     */
    public function __construct(
        private LocationsServices   $locationsServices,
        private GuestServices       $guestServices,
        private RsvpServices        $rsvpServices,
        private EventCommentService $eventCommentService,
        private EventModules        $modules,
    ) {}

    /**
     * Loads event-related data.
     *
     * @param Events $event Instance of the Events model representing the specific event.
     *
     * @return array Associative array containing event data including menus, features, guests, RSVP information, save-the-date details, menu guests, and locations.
     */
    public function loadEventData(Events $event): array
    {
        // A module that is not active for this event hydrates as null: the data of a locked module is not handed out.
        $active = fn (string $module) => $this->modules->isActive($event, $module);

        return [
            'menus' => $active('menus') ? $this->loadMenus($event) : null,
            'eventFeatures' => $this->loadEventFeatures($event),
            // The guest list and the answers have their own screen and endpoints; loading them here cost a QR per guest on every app load.
            'guests' => null,
            'rsvp' => null,
            'saveTheDate' => $active('save_the_date') ? $this->loadSaveTheDate($event) : null,
            'menuGuests' => $active('menus') ? $this->loadMenuGuests($event) : null,
            'location' => $active('locations') ? $this->loadLocation($event) : null,
            'comments' => $active('comments') ? $this->loadComments($event) : null,
        ];
    }

    /**
     * Loads event menus.
     *
     * @param Events $event
     * @return AnonymousResourceCollection|null
     */
    private function loadMenus(Events $event): ?AnonymousResourceCollection
    {
        if (!$event->menus || $event->menus->isEmpty()) {
            return null;
        }

        return MenuResource::collection($event->menus);
    }

    /**
     * Loads event features.
     *
     * @param Events $event
     * @return EventFeatureResource|null
     */
    private function loadEventFeatures(Events $event): ?EventFeatureResource
    {
        if (!$event->eventFeature) {
            return null;
        }

        return EventFeatureResource::make($event->eventFeature);
    }

    /**
     * Loads save-the-date details for the event.
     *
     * @param Events $event
     * @return SaveTheDateResource|null
     */
    private function loadSaveTheDate(Events $event): ?SaveTheDateResource
    {
        if (!$event->saveTheDate) {
            return null;
        }

        return SaveTheDateResource::make($event->saveTheDate);
    }

    /**
     * Loads menu guests for the event.
     *
     * @param Events $event
     * @return array|null
     */
    private function loadMenuGuests(Events $event): ?array
    {
        $perPage = request()->input('perPage', 10);

        $menuGuests = Guest::query()
            ->with(['selectedMenuItems']) // Eager loading to prevent N+1
            ->where('event_id', $event->id)
            ->paginate($perPage);

        if ($menuGuests->isEmpty()) {
            return null;
        }

        return GuestMenuConfirmationResource::collection($menuGuests)->toResponse(request())->getData(true);
    }

    /**
     * Loads event locations.
     *
     * @param Events $event
     * @return EventLocation|null
     */
    private function loadLocation(Events $event): ?EventLocation
    {
        return $this->locationsServices->getEventLocation($event);
    }

    /**
     * Loads event comments.
     *
     * @param Events $event
     * @return array|null
     */
    private function loadComments(Events $event): ?array
    {
        $perPage = request()->input('commentsPerPage', 10);
        $comments = $this->eventCommentService->listForEventPaginated($event, [], $perPage);

        if ($comments->isEmpty()) {
            return null;
        }

        return EventCommentResource::collection($comments)->toResponse(request())->getData(true);
    }
}
