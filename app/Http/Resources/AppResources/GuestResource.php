<?php

namespace App\Http\Resources\AppResources;

use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Matrix\Decomposition\QR;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class GuestResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $mainAppUrl = config('app.frontend_app.url');
        $invitationUrl = "$mainAppUrl/event/$this->event_id/guest/$this->access_code";
        $qrImage = QrCode::format('png')->size(200)->generate($invitationUrl);
        
        return [
            'id' => $this->id,
            'eventId' => $this->event_id,
            'firstName' => $this->first_name,
            'lastName' => $this->last_name,
            'email' => $this->email,
            'phoneNumber' => $this->phone_number,
            'phoneConfirmed' => $this->phone_confirmed,
            'extraPhone' => $this->extra_phone,
            'confirmed' => $this->confirmed,
            'confirmedDate' => $this->confirmed_date,
            'accessCode' => $this->access_code,
            'codeUsedTimes' => $this->code_used_times,
            'companionType' => $this->companion_type,
            'companionQty' => $this->companion_qty,
            'companions' => GuestCompanionResource::collection($this->companions),
            'invitationQR' => base64_encode($qrImage)
        ];
    }
}
