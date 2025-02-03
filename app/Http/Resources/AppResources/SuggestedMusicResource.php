<?php

namespace App\Http\Resources\AppResources;

use App\Http\Resources\MainGuestResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SuggestedMusicResource extends JsonResource
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
            'name' => $this->name,
            'platform' => $this->platform,
            'platformUrl' => $this->platform_url,
            'suggestedBy' => MainGuestResource::make($this->suggestedBy)
        ];
    }
}
