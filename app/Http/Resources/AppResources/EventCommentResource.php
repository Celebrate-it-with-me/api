<?php

namespace App\Http\Resources\AppResources;

use App\Http\Resources\MainGuestResource;
use App\Http\Resources\UserResource;
use App\Models\MainGuest;
use App\Models\User;
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
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'created_by_class' => $this->created_by_class,
            'created_by_id' => $this->created_by_id,
            'created_by' => $this->getCreatedBy(),
            'comment' => $this->comment,
            'created_at' => $this->created_at->diffForHumans(),
            'updated_at' => $this->updated_at->diffForHumans(),
        ];
    }
    
    /**
     *
     */
    private function getCreatedBy()
    {
        if ($this->created_by_class === User::class) {
            return UserResource::make(User::find($this->created_by_id));
        }
        
        return MainGuestResource::make(MainGuest::find($this->created_by_id));
    }
}
