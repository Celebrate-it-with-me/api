<?php

namespace App\Http\Resources\AppResources;

use App\Http\Services\AppServices\BackgroundMusicServices;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class BackgroundMusicResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * The icon fields and `autoplay` are kept for the old screen only: the public invitation ignores them.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'eventId' => $this->event_id,
            'songUrl' => $this->song_url ? url(Storage::disk(config('filesystems.media_disk'))->url($this->song_url)) : null,
            'title' => $this->title,
            'mimeType' => BackgroundMusicServices::mimeTypeOf($this->song_url),
            'sizeBytes' => BackgroundMusicServices::sizeOf($this->song_url),
            'iconSize' => $this->icon_size,
            'iconPosition' => $this->icon_position,
            'iconColor' => $this->icon_color,
            'autoplay' => (bool) $this->auto_play,
        ];
    }
}
