<?php

namespace App\Http\Resources\AppResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaveTheDateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'show_countdown' => $this->show_countdown,
            'use_add_to_calendar' => $this->use_add_to_calendar,
            
            'date_source' => $this->date_source,
            'custom_starts_at' => $this->custom_starts_at?->toIso8601String(),
            
            'countdown_units' => $this->countdown_units ?? [],
            'countdown_finish_behavior' => $this->countdown_finish_behavior,
            
            'calendar_providers' => $this->calendar_providers ?? [],
            'calendar_mode' => $this->calendar_mode,
            
            'calendar_location_override' => $this->calendar_location_override,
            'calendar_description_override' => $this->calendar_description_override,
            
            // helper útil para frontend
            'is_enabled' => true,
        ];
    }
}
