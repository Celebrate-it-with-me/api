<?php

namespace App\Http\Resources\AppResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SuggestedMusicVoteResource extends JsonResource
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
            'suggestedMusicId' => $this->suggested_music_id,
            'mainGuestId' => $this->main_guest_id,
            'voteType' => $this->vote_type,
        ];
    }
}
