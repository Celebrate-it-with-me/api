<?php

namespace App\Http\Resources\AppResources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
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
        $isMainGuest = is_null($this->parent_id);

        $invitationUrl = $isMainGuest
            ? config('app.frontend_app.url') . "/event/{$this->event_id}/guest/{$this->code}"
            : null;

        return [
            'id' => $this->id,
            'eventId' => $this->event_id,
            'parentId' => $this->parent_id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'gender' => $this->gender,
            'rsvpStatus' => $this->rsvp_status,
            'rsvpStatusDate' => $this->rsvp_status_date
                ? $this->rsvp_status_date->diffForHumans()
                : null,
            'mealPreference' => $this->meal_preference,
            'allergies' => $this->allergies,
            'seatNumber' => $this->getSeatNumber(),
            'notes' => $this->notes,
            'code' => $isMainGuest ? $this->code : null,
            'menuSelected' => $this->getOptimizedMenuWithItems(),
            'invitationUrl' => $invitationUrl,
            'invitationQR' => $this->when(
                $isMainGuest && $invitationUrl,
                fn() => base64_encode(QrCode::format('png')->size(200)->generate($invitationUrl))
            ),

            'companions' => $this->when(
                $isMainGuest,
                GuestResource::collection($this->whenLoaded('companions'))
            ),
            'invitations' => $this->when(
                $isMainGuest,
                GuestInvitationResource::collection($this->whenLoaded('invitations'))
            ),
            'rsvpLogs' => $this->when(
                $isMainGuest,
                GuestRsvpLogResource::collection($this->whenLoaded('rsvpLogs'))
            ),
        ];
    }

    private function getOptimizedMenuWithItems(): array
    {
        if (!$this->assigned_menu_id || !$this->relationLoaded('assignedMenu')) {
            return [];
        }

        $menu = $this->assignedMenu;

        if (!$menu) {
            return [];
        }

        $groupedItems = $menu->menuItems->groupBy('type')->map(function ($items) {
            return $items->values()->toArray();
        });

        return [
            'menu' => $menu->toArray(),
            'menuItems' => $groupedItems
        ];
    }
    
    private function getSeatNumber(): ?string
    {
        $seat = '';
        
        $tableAssignment = $this->relationLoaded('tableAssignment') ? $this->tableAssignment : null;
        
        if ($tableAssignment) {
            $fullSeat = $tableAssignment->seat_number;
            $parts = explode(' - ', $fullSeat);
            $seat = count($parts) > 1 ? $parts[1] : $fullSeat;
        }
        
        return $seat;
    }
}
