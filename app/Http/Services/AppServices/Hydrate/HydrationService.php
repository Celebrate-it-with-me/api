<?php

namespace App\Http\Services\AppServices\Hydrate;

use App\Http\Resources\AppResources\EventFeatureResource;
use App\Http\Resources\AppResources\EventResource;
use App\Http\Resources\AppResources\GuestResource;
use App\Http\Resources\AppResources\RsvpResource;
use App\Http\Resources\AppResources\SaveTheDateResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class HydrationService
{
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
        
        $menus = $activeEvent->menus ?? null;
        $eventFeatures = $activeEvent->eventFeatures ?? null;
        $guests = $activeEvent->guests ?? null;
        $rsvp = $activeEvent->rsvp ?? null;
        $saveTheDate = $activeEvent->saveTheDate ?? null;
        
        return response()->json([
            'events' => $events ? EventResource::collection($events) : null,
            'activeEvent' => $activeEvent ? EventResource::make($activeEvent) : null,
            'menus' => $menus ? $menus : null, // Todo create MenuResource
            'eventFeatures' => $eventFeatures ? EventFeatureResource::make($eventFeatures) : null,
            'guests' => $guests ? GuestResource::collection($guests) : null,
            'rsvp' => $rsvp ? RsvpResource::make($rsvp) : null,
            'saveTheDate' => $saveTheDate ? SaveTheDateResource::make($saveTheDate) : null,
        ]);
        
    }
}
