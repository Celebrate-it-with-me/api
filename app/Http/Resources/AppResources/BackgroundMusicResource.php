<?php

namespace App\Http\Resources\AppResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

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
            'autoplay' => (bool) $this->auto_play,
            'songUrl' => $this->song_url
                ? url(Storage::url($this->song_url))
                : null,
        ];
    }
}
