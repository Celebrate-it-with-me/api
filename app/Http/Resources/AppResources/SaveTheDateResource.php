<?php

namespace App\Http\Resources\AppResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class SaveTheDateResource extends JsonResource
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
            'stdTitle' => $this->std_title,
            'stdSubTitle' => $this->std_subtitle,
            'backgroundColor' => $this->background_color,
            'useCountdown' => $this->use_countdown,
            'useAddToCalendar' => $this->use_add_to_calendar,
            'imageUrl' => $this->image_url
                ? url(Storage::url($this->image_url))
                : null,
            'isEnabled' => $this->is_enabled,
        ];
    }
}
