<?php

namespace App\Http\Resources\AppResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventLocationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'eventId' => $this->event_id,
            'type' => $this->type,
            'name' => $this->name,
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'zipCode' => $this->zip_code,
            'country' => $this->country,
            'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            'placeId' => $this->place_id,
            'directions' => $this->notes,
            'images' => EventLocationImageResource::collection($this->whenLoaded('eventLocationImages')),
        ];
    }
}
