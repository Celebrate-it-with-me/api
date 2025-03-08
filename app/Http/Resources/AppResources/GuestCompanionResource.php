<?php

namespace App\Http\Resources\AppResources;

use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GuestCompanionResource extends JsonResource
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
            'firstName' => $this->first_name,
            'lastName' => $this->last_name,
            'email' => $this->email ?? '',
            'phoneNumber' => $this->phone_number ?? '',
            'phoneConfirmed' => $this->phone_confirmed,
            'mealPreference' => $this->meal_preference,
            'confirmed' => $this->confirmed,
            'confirmedDate' => $this->confirmed_date,
        ];
    }
}
