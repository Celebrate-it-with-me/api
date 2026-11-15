<?php

namespace App\Http\Resources\AppResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GuestRsvpLogResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'guestId' => $this->guest_id,
            'status' => $this->status,
            'changedAt' => $this->changed_at,
            'changedBy' => $this->changed_by,
            'notes' => $this->notes,
        ];
    }
}
