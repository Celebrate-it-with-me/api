<?php

namespace App\Http\Controllers\AppControllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\app\StoreEventsRequest;
use App\Http\Requests\app\UpdateEventsRequest;
use App\Http\Resources\AppResources\EventResource;
use App\Http\Resources\AppResources\GuestResource;
use App\Http\Resources\AppResources\TemplateResource;
use App\Http\Services\AppServices\EventsServices;
use App\Models\Events;
use App\Models\Guest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Throwable;

class TemplateController extends Controller
{
    public function getEventData(Events $event, string $guestCode): JsonResponse|TemplateResource
    {
        try {
            return new TemplateResource($event, $guestCode);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage(), 'data' => []], 500);
        }
    }
    
    /**
     * Fetches guest data and their companion information based on a provided guest code.
     *
     * This method retrieves the main guest's information using their unique guest code,
     * counts the number of companions associated with the main guest, and formats all
     * relevant data into a structured JSON response.
     *
     * In case of any error during the process, it returns a JSON response with
     * the error message and an empty data array.
     *
     * @param string $guestCode The unique code identifying the guest.
     *
     * @return JsonResponse The structured response containing the guest's data or
     * an error message in case of failure.
     */
    public function getGuestData(Events $event, string $guestCode): JsonResponse
    {
        try {
            $mainGuest = Guest::query()
                ->where('code', $guestCode)
                ->first();
            
            $companionQty = Guest::query()
                ->where('parent_id', $mainGuest->id)
                ->count();
            
            $guestData = [
                'mainGuest' => [
                    'id' => $mainGuest->id,
                    'eventId' => $mainGuest->event_id,
                    'name' => $mainGuest->name,
                    'email' => $mainGuest->email,
                    'phone' => $mainGuest->phone,
                    'mealPreference' => $mainGuest->meal_preference,
                    'accessCode' => $mainGuest->code,
                    'rsvpStatus' => $mainGuest->rsvp_status,
                    'rsvpStatusDate' => $mainGuest->rsvp_status_date
                        ? $mainGuest->rsvp_status_date->diffForHumans()
                        : null,
                    'notes' => $mainGuest->notes,
                    'tags' => $mainGuest->tags,
                    'companionQty' => $companionQty,
                    'companions' => GuestResource::collection($mainGuest->companions)
                ]
            ];
            
            return response()->json(['data' => $guestData], 200);
            
        } catch (Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(). ' ' .$e->getLine()
            ], 500);
        }
    }
    
}
