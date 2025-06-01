<?php

namespace App\Http\Resources\AppResources;

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
            'platformId' => $this->platformId,
            'title' => $this->title,
            'artist' => $this->artist,
            'album' => $this->album,
            'platform' => $this->platform,
            'thumbnailUrl' => $this->thumbnailUrl,
            'suggestedBy' => $this->suggested_by,
        ];
    }
}
