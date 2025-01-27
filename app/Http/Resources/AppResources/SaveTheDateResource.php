<?php

namespace App\Http\Resources\AppResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaveTheDateResource extends JsonResource
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
            'eventId' => $this->event_id,
            'message' => $this->message,
            'imageUrl' => $this->image_url,
            'isEnabled' => $this->is_enabled,
        ];
    }
}
