<?php

namespace App\Http\Resources\AppResources;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GuestInvitationResource extends JsonResource
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
            'guestId' => $this->guest_id,
            'channel' => $this->channel,
            'sentAt' => Carbon::createFromFormat('Y-m-d H:i:s', $this->sent_at)->diffForHumans(),
            'status' => $this->status,
            'messagePreview' => $this->message_preview,
            'responsePayload' => $this->response_payload,
            'attemptedBy' => $this->attempted_by,
        ];
    }
    
}
