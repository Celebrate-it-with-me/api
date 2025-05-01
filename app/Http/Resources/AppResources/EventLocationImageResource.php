<?php

namespace App\Http\Resources\AppResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;


class EventLocationImageResource extends JsonResource
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
            'eventLocationId' => $this->event_location_id,
            'path' => $this->path,
            'caption' => $this->caption,
            'order' => $this->order,
            'source' => $this->source,
        ];
    }
    
}
