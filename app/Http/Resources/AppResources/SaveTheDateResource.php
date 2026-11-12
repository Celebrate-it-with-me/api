<?php

namespace App\Http\Resources\AppResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaveTheDateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'message' => $this->message,
            'showCountdown' => (bool) $this->show_countdown,
            'useAddToCalendar' => (bool) $this->use_add_to_calendar,
        ];
    }
}
