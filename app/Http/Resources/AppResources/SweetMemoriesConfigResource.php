<?php

namespace App\Http\Resources\AppResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SweetMemoriesConfigResource extends JsonResource
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
            'backgroundColor' => $this->background_color,
            'maxPictures' => $this->max_pictures,
        ];
    }
}
