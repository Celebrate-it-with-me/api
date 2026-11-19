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
            // Said "not attending": still seatable, but the screen warns.
            'declined' => $this->rsvp_status === 'not-attending',
            // Whose companion this person is (null for a main guest).
            'companionOf' => $this->parent_id && $this->relationLoaded('parent') && $this->parent
                ? ['id' => $this->parent->id, 'name' => $this->parent->name]
                : null,
            // Bare seat number at the table (the screen writes "Asiento 3" / "Seat 3"); null when not seated or not loaded.
            'seatNumber' => $this->relationLoaded('tableAssignment') ? $this->tableAssignment?->seat_number : null,
            'notes' => $this->notes,
        ];
    }
}
