<?php

namespace App\Http\Resources\AppResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class GuestResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isMainGuest = is_null($this->parent_id);
        
        $invitationUrl = $isMainGuest
            ? config('app.frontend_app.url') . "/event/{$this->event_id}/guest/{$this->code}"
            : null;
        
        return [
            'id' => $this->id,
            'eventId' => $this->event_id,
            'parentId' => $this->parent_id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'rsvpStatus' => $this->rsvp_status,
            'mealPreference' => $this->meal_preference,
            'allergies' => $this->allergies,
            'seatNumber' => $this->seat_number,
            'notes' => $this->notes,
            'code' => $isMainGuest ? $this->code : null,
            
            // 🎯 Añadimos ambos campos solo si es el guest principal
            'invitationUrl' => $invitationUrl,
            'invitationQR' => $isMainGuest && $invitationUrl
                ? base64_encode(QrCode::format('png')->size(200)->generate($invitationUrl))
                : null,
            
            'companions' => $this->when(
                $isMainGuest,
                GuestResource::collection($this->companions)
            ),
        ];
    }
    
}
