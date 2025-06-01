<?php

namespace App\Http\Resources\AppResources;

use App\Http\Resources\MainGuestResource;
use App\Http\Resources\UserResource;
use App\Models\Guest;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventCommentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $created = $this->getCreatedBy();
        $createdBy = ($created instanceof Guest)
            ? GuestResource::make($created)
            : UserResource::make($created);

        $author = $createdBy->name;

        return [
            'id' => $this->id,
            'eventId' => $this->event_id,
            'createdByClass' => $this->created_by_class,
            'createdById' => $this->created_by_id,
            'createdBy' => $createdBy,
            'author' => $author,
            'comment' => $this->comment,
            'createdAt' => $this->created_at->diffForHumans(),
            'updatedAt' => $this->updated_at->diffForHumans(),
        ];
    }

    /**
     * Retrieves the creator of this resource and returns it as either a UserResource or MainGuestResource
     * based on the class type of the creator.
     *
     * @return Collection|Model|User|User[] The resource representation of the creator.
     */
    private function getCreatedBy(): User|array|Collection|Model
    {
        if ($this->created_by_class === User::class) {
            return User::find($this->created_by_id);
        }

        return Guest::find($this->created_by_id);
    }
}
