<?php

namespace App\Http\Resources\AppResources\Seating;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A guest as the seating screens need them. Light on purpose: no QR code, menu or companions per row. */
class SeatGuestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'eventId' => $this->event_id,
            'parentId' => $this->parent_id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'gender' => $this->gender,
            'rsvpStatus' => $this->rsvp_status,
            'notes' => $this->notes,
        ];
    }
}
