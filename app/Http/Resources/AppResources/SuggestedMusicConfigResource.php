<?php

namespace App\Http\Resources\AppResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SuggestedMusicConfigResource extends JsonResource
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
            'title' => $this->title,
            'subTitle' => $this->sub_title,
            'mainColor' => $this->main_color,
            'secondaryColor' => $this->secondary_color,
            'usePreview' => $this->use_preview,
            'useVoteSystem' => $this->use_vote_system,
            'searchLimit' => $this->search_limit,
        ];
    }
}
