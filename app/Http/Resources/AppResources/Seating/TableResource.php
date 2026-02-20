<?php

namespace App\Http\Resources\AppResources\Seating;

use App\Http\Resources\AppResources\GuestResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;


class TableResource extends JsonResource
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
            'event_id' => $this->event_id,
            'name' => $this->name,
            'capacity' => $this->capacity,
            'type' => $this->type,
            'priority' => $this->priority,
            'reserved_for' => $this->reserved_for,
            'location' => $this->location,
            
            // Computed attributes
            'available_seats' => $this->available_seats,
            'occupancy_percentage' => $this->occupancy_percentage,
            'is_full' => $this->isFull(),
            
            // Assigned guests (when loaded)
            'assigned_guests' => GuestResource::collection($this->whenLoaded('assignedGuests')),
            
            // Metadata
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
    
}
