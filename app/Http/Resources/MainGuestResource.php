<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MainGuestResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $partyMembers = is_array($this->partyMembers)
            ? $this->partyMembers
            : json_decode($this->partyMembers, true);

        return [
            'id' => $this->id,
            'firstName' => $this->first_name,
            'lastName' => $this->last_name,
            'email' => $this->email,
            'partyMembers' => $partyMembers,
            'partySize' => count($partyMembers) + 1,
            'phoneNumber' => $this->phone_number,
            'accessCode' => $this->access_code,
            'confirmed' => $this->confirmed,
            'confirmedDate' => Carbon::parse($this->confirmed_date)->toFormattedDateString(),
            'created_at' => $this->created_at->toFormattedDateString(),
        ];
    }
}
