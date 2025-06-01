<?php

namespace App\Http\Resources\AppResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MenuResource extends JsonResource
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
            'allowMultipleChoices' => $this->allow_multiple_choices,
            'allowCustomRequest' => $this->allow_custom_request,
            'isDefault' => $this->is_default,
        ];
    }
}
