<?php

namespace App\Http\Resources\AppResources\Seating;

use App\Http\Resources\AppResources\GuestResource;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;


class TableAssignmentResource extends JsonResource
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
            'table_id' => $this->table_id,
            'guest_id' => $this->guest_id,
            'seat_number' => $this->seat_number,
            'assigned_at' => $this->assigned_at?->toISOString(),
            'assigned_by' => $this->assigned_by,
            
            // Relations (when loaded)
            'table' => new TableResource($this->whenLoaded('table')),
            'guest' => new GuestResource($this->whenLoaded('guest')),
            'assigner' => new UserResource($this->whenLoaded('assignedBy')),
            
            // Metadata
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
    
}
