<?php

namespace App\Http\Resources\AppResources;

use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventResource extends JsonResource
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
            'eventName' => $this->event_name,
            'eventDescription' => $this->event_description,
            'startDate' => $this->start_date?->format('m/d/Y H:i'),
            'endDate' => $this->end_date?->format('m/d/Y H:i'),
            'organizer' => UserResource::make($this->organizer),
            'status' => $this->status,
            'customUrlSlug' => $this->custom_url_slug,
            'visibility' => $this->visibility,
            'createdAt' => $this->created_at->toDateTimeString(),
            'updatedAt' => $this->updated_at->toDateTimeString(),
            'selected' => false,
            'saveTheDate' => (bool) $this->saveTheDate?->is_enabled ?? false,
            'eventFeature' => EventFeatureResource::make($this->eventFeature)
        ];
    }
}
