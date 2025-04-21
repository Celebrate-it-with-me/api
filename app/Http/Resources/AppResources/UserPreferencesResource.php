<?php

namespace App\Http\Resources\AppResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserPreferencesResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'language' => $this->language,
            'timezone' => $this->timezone,
            'darkMode' => $this->dark_mode,
            'dateFormat' => $this->date_format,
            'notifyByEmail' => $this->notify_by_email,
            'notifyBySms' => $this->notify_by_sms,
            'smartTips' => $this->smart_tips,
        ];
    }
}
