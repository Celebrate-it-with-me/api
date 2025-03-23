<?php

namespace App\Http\Resources\AppResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BackgroundMusicResource extends JsonResource
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
            'iconSize' => $this->icon_size,
            'iconPosition' => $this->icon_position,
            'iconColor' => $this->icon_color,
            'autoplay' => $this->auto_play,
            'songUrl' => $this->song_url,
        ];
    }
}
