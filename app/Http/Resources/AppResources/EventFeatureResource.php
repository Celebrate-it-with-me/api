<?php

namespace App\Http\Resources\AppResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventFeatureResource extends JsonResource
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
            'saveTheDate' => $this->save_the_date,
            'rsvp' => $this->rsvp,
            'menu' => $this->menu,
            'sweetMemories' => $this->sweet_memories,
            'music' => $this->music,
            'backgroundMusic' => $this->background_music,
            'eventComments' => $this->event_comments,
            'seatsAccommodation' => $this->seats_accommodation,
            'preview' => $this->preview,
            'budget' => $this->budget,
            'analytics' => $this->analytics
        ];
    }
}
