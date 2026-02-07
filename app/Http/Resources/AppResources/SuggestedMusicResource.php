<?php

namespace App\Http\Resources\AppResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SuggestedMusicResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'eventId' => $this->event_id,

            // Song details
            'title' => $this->title,
            'artist' => $this->artist,
            'album' => $this->album,
            'platform' => $this->platform,
            'platformId' => $this->platformId,
            'thumbnailUrl' => $this->thumbnailUrl,
            'previewUrl' => $this->previewUrl ?? null,

            // Who suggested
            'suggestedBy' => [
                'id' => $this->suggested_by_id,
                'entity' => $this->suggested_by_entity,
                'name' => $this->suggestedBy?->name ?? 'Unknown',
                'isOrganizer' => $this->isSuggestedByOrganizer(),
            ],

            // Vote counts
            'votes' => [
                'up' => $this->upvotesCount ?? $this->upvotes_count ?? 0,
                'down' => $this->downvotesCount ?? $this->downvotes_count ?? 0,
                'netScore' => $this->netScore ?? (
                        ($this->upvotes_count ?? 0) - ($this->downvotes_count ?? 0)
                    ),
            ],

            // User's vote (if authenticated MainGuest)
            'userVote' => $this->when(
                $request->user() && method_exists($request->user(), 'id'),
                fn() => $this->getVoteByGuest($request->user()->id)?->vote_type
            ),

            // Timestamps
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
