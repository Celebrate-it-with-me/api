<?php

namespace App\Http\Resources\AppResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RsvpResource extends JsonResource
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
            'description' => $this->description,
            'customFields' => $this->custom_fields,
            'confirmationDeadline' => $this->confirmation_deadline,
        ];
    }
}
