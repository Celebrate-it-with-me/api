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
use App\Models\Guest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class HydrationService
{
    private LocationsServices $locationsServices;
    private EventPermissionService $eventPermissionService;
    
    /**
     * HydrationService constructor.
     * @param LocationsServices $locationsServices
     * @param EventPermissionService $eventPermissionService
     */
    public function __construct(LocationsServices $locationsServices, EventPermissionService $eventPermissionService)
    {
        $this->eventPermissionService = $eventPermissionService;
        $this->locationsServices = $locationsServices;
    }
    
    public function hydrate(User $user): JsonResponse
    {
        Log::info('Checking User', ['user' => $user]);
        $user->load(['activeEvent', 'organizedEvents']);
        $events = $user->organizedEvents;
        $activeEvent = $user->activeEvent()->with([
            'menus',
            'eventFeature',
            'guests',
            'rsvp',
            'saveTheDate'
        ])->first();
        
        if (!$activeEvent || !$user->hasEventRole($activeEvent)) {
            return response()->json([
                'message' => 'You do not have permission to access this event. Please contact the event organizer.',
            ], 403);
        }
        
        $userLoggedPermissions = $user->getEventPermissions($activeEvent);
        
        if (!$activeEvent) {
            return response()->json([
                'events' => $events ? EventResource::collection($events) : null,
                'activeEvent' => null,
                'menus' => null,
                'eventFeatures' => null,
                'guests' => null,
                'rsvp' => null,
                'saveTheDate' => null,
            ]);
        }
        
        $rsvpGuests = (app()->make(RsvpServices::class)->getRsvpGuests($activeEvent));
        
        $menus = $activeEvent->menus ?? null;
        $eventFeatures = $activeEvent->eventFeatures ?? null;
        $guests = (app()->make(GuestServices::class))->getEventsGuests($activeEvent);
        $rsvp = $rsvpGuests
            ? GuestResource::collection($rsvpGuests)
                ->response()->getData(true)
            : null;
        $saveTheDate = $activeEvent->saveTheDate ?? null;
        $menuGuests = Guest::query()
            ->with(['selectedMenuItems'])
            ->where('event_id', $activeEvent->id)
            ->paginate(10);
        
        $locations = $this->locationsServices->getEventLocations($activeEvent);
        
        
        return response()->json([
            'events' => $events ? EventResource::collection($events) : null,
            'activeEvent' => EventResource::make($activeEvent),
            'menus' => $menus
                ? MenuResource::collection($menus)
                : null,
            'menuGuests' => $menuGuests
                ? GuestMenuConfirmationResource::collection($menuGuests)->response()->getData(true)
                : null,
            'eventFeatures' => $eventFeatures ? EventFeatureResource::make($eventFeatures) : null,
            'guests' => $guests
                ? GuestResource::collection($guests)->response()->getData(true)
                : null,
            'rsvp' => $rsvp,
            'saveTheDate' => $saveTheDate ? SaveTheDateResource::make($saveTheDate) : null,
            'locations' => $locations,
            'userPermissions' => $userLoggedPermissions,
        ]);
    }
}
