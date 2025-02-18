<?php

namespace App\Http\Resources\AppResources;

use App\Http\Resources\UserResource;
use App\Models\GuestCompanion;
use App\Models\MainGuest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TemplateResource extends JsonResource
{
    private MainGuest $mainGuest;
    
    public function __construct($resource, string $guestCode)
    {
        parent::__construct($resource);
        $this->mainGuest = $this->initMainGuest($guestCode);
    }
    
    /**
     * Init Main Guest Data.
     * @param string $guestCode
     * @return MainGuest
     */
    private function initMainGuest(string $guestCode): MainGuest
    {
        return MainGuest::query()
            ->where('access_code', $guestCode)
            ->first();
    }
    
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'event' => [
                'id' => $this->id,
                'eventName' => $this->event_name,
                'eventDescription' => $this->event_description,
                'eventDate' => $this->event_date,
                'organizer' => UserResource::make($this->organizer),
                'status' => $this->status,
                'customUrlSlug' => $this->custom_url_slug,
                'visibility' => $this->visibility,
                'createdAt' => $this->created_at->toDateTimeString(),
                'updatedAt' => $this->updated_at->toDateTimeString(),
                'selected' => false,
                'saveTheDate' => SaveTheDateResource::make($this->saveTheDate)
            ],
            'mainGuest' => [
                'id' => $this->mainGuest->id,
                'eventId' => $this->mainGuest->event_id,
                'firstName' => $this->mainGuest->first_name,
                'lastName' => $this->mainGuest->last_name,
                'email' => $this->mainGuest->email,
                'phoneNumber' => $this->mainGuest->phone_number,
                'mealPreference' => $this->mainGuest->meal_preference,
                'accessCode' => $this->mainGuest->access_code,
                'codeUsedTimes' => $this->mainGuest->code_used_times,
                'confirmed' => $this->mainGuest->confirmed,
                'rsvpCompleted' => $this->mainGuest->confirmed !== 'unused',
                'confirmedDate' => $this->mainGuest->confirmed_date,
                'companionType' => $this->mainGuest->companion_type,
                'companionQty' => $this->mainGuest->companion_qty,
                'companions' => GuestCompanionResource::collection($this->mainGuest->companions)
            ]
        ];
    }
}
