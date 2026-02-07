<?php

namespace App\Http\Resources\AppResources\EventComment;

use BackedEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// This is the last version of EventCommentResource, we will need to clean others versions
class EventCommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $author = $this->whenLoaded('authorable');

        return [
            'id' => $this->id,
            'eventId' => $this->event_id,

            'comment' => $this->comment,
            'status' => $this->status instanceof BackedEnum ? $this->status->value : (string) $this->status,
            'isPinned' => (bool) $this->is_pinned,
            'isFavorite' => (bool) $this->is_favorite,

            'createdAt' => optional($this->created_at)->toISOString(),
            'updatedAt' => optional($this->updated_at)->toISOString(),

            'author' => $author ? [
                'id' => $author->id,
                'name' => $this->resolveAuthorName($author),
                'type' => class_basename($author),
            ] : null,
        ];
    }

    private function resolveAuthorName($author): string
    {
        return $author->name;
    }

}
