<?php

namespace App\Http\Services\AppServices\Hydrate;

use App\Http\Resources\AppResources\EventFeatureResource;
use App\Http\Resources\AppResources\EventResource;
use App\Http\Resources\AppResources\GuestMenuConfirmationResource;
use App\Http\Resources\AppResources\GuestResource;
use App\Http\Resources\AppResources\MenuResource;
use App\Http\Resources\AppResources\SaveTheDateResource;
use App\Http\Services\AppServices\GuestServices;
use App\Http\Services\AppServices\LocationsServices;
use App\Http\Services\AppServices\RsvpServices;
use App\Http\Services\Permissions\EventPermissionService;
use App\Models\Events;
use App\Models\Guest;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Service responsible for hydrating user data with their events and related information.
 */
class HydrationService
{
    /**
     * @var LocationsServices
     */
    private LocationsServices $locationsServices;


    /**
     * @var GuestServices
     */
    private GuestServices $guestServices;

    /**
     * @var RsvpServices
     */
    private RsvpServices $rsvpServices;

    /**
     * HydrationService constructor.
     *
     * @param LocationsServices $locationsServices
     * @param EventPermissionService $eventPermissionService
     * @param GuestServices $guestServices
     * @param RsvpServices $rsvpServices
     */
    public function __construct(
        LocationsServices $locationsServices,
        EventPermissionService $eventPermissionService,
        GuestServices $guestServices,
        RsvpServices $rsvpServices
    ) {
        $this->locationsServices = $locationsServices;
        $this->guestServices = $guestServices;
        $this->rsvpServices = $rsvpServices;
    }

    /**
     * Hydrate the user with their events and other related data.
     *
     * @param User $user The user to hydrate with data
     * @return JsonResponse The hydrated data as JSON response
     */
    public function hydrate(User $user): JsonResponse
    {
        $events = $user->accessibleEvents();
        $activeEvent = $this->getActiveEvent($user);
        
        if (!$activeEvent) {
            return $this->createEmptyResponse($events);
        }
        
        if (!$user->hasEventPermission($activeEvent, 'view_event')) {
            return $this->createUnauthorizedResponse();
        }
        
        $userLoggedPermissions = $user->getEventPermissions($activeEvent);
        
        $data = $this->loadEventData($activeEvent);
        
        return response()->json(array_merge(
            [
                'events' => $events ? EventResource::collection($events) : null,
                'activeEvent' => EventResource::make($activeEvent),
                'userPermissions' => $userLoggedPermissions,
            ],
            $data
        ));
    }

    /**
     * Get the active event for a user with preloaded relationships.
     *
     * @param User $user The user to get the active event for
     * @return Events|null The active event or null if none exists
     */
    private function getActiveEvent(User $user): ?Events
    {
        return Events::query()
            ->with(['menus', 'eventFeature', 'guests', 'rsvp', 'saveTheDate'])
            ->where('id', $user->last_active_event_id)
            ->first();
    }

    /**
     * Create a response for when the user has no active event.
     *
     * @param Collection|null $events The user's organized events
     * @return JsonResponse The empty response
     */
    private function createEmptyResponse(?Collection $events): JsonResponse
    {
        return response()->json([
            'events' => $events ? EventResource::collection($events) : null,
            'activeEvent' => null,
            'menus' => null,
            'eventFeatures' => null,
            'guests' => null,
            'rsvp' => null,
            'saveTheDate' => null,
            'menuGuests' => null,
            'locations' => null,
            'userPermissions' => null,
        ]);
    }

    /**
     * Create a response for when the user is not authorized to access the event.
     *
     * @return JsonResponse The unauthorized response
     */
    private function createUnauthorizedResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'You do not have permission to access this event. Please contact the event organizer.',
        ], 403);
    }

    /**
     * Load all data related to an event.
     *
     * @param Events $event The event to load data for
     * @return array The loaded data
     */
    private function loadEventData(Events $event): array
    {
        // Get RSVP guests
        $rsvpGuests = $this->rsvpServices->getRsvpGuests($event);
        $rsvp = $rsvpGuests
            ? GuestResource::collection($rsvpGuests)->response()->getData(true)
            : null;

        // Get event guests
        $guests = $this->guestServices->getEventsGuests($event);
        $formattedGuests = $guests
            ? GuestResource::collection($guests)->response()->getData(true)
            : null;

        // Get menu guests
        $menuGuests = $this->getMenuGuests($event);
        $formattedMenuGuests = $menuGuests
            ? GuestMenuConfirmationResource::collection($menuGuests)->response()->getData(true)
            : null;

        // Get locations
        $locations = $this->locationsServices->getEventLocations($event);

        // Get other event data
        $menus = $event->menus ?? null;
        $eventFeatures = $event->eventFeatures ?? null;
        $saveTheDate = $event->saveTheDate ?? null;

        return [
            'menus' => $menus ? MenuResource::collection($menus) : null,
            'menuGuests' => $formattedMenuGuests,
            'eventFeatures' => $eventFeatures ? EventFeatureResource::make($eventFeatures) : null,
            'guests' => $formattedGuests,
            'rsvp' => $rsvp,
            'saveTheDate' => $saveTheDate ? SaveTheDateResource::make($saveTheDate) : null,
            'locations' => $locations,
        ];
    }

    /**
     * Get guests with their menu selections for an event.
     *
     * @param Events $event The event to get menu guests for
     * @return LengthAwarePaginator The paginated menu guests
     */
    private function getMenuGuests(Events $event): LengthAwarePaginator
    {
        return Guest::query()
            ->with(['selectedMenuItems'])
            ->where('event_id', $event->id)
            ->paginate(10);
    }
}
