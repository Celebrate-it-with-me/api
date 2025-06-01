<?php

namespace App\Http\Resources\AppResources;

use App\Models\Menu;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Log;
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
            ? config('app.frontend_app.url') . "event/{$this->event_id}/guest/{$this->code}"
            : null;

        Log::info('checking status rsvp', [$this]);

        return [
            'id' => $this->id,
            'eventId' => $this->event_id,
            'parentId' => $this->parent_id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'rsvpStatus' => $this->rsvp_status,
            'rsvpStatusDate' => $this->rsvp_status_date
                ? $this->rsvp_status_date->diffForHumans()
                : null,
            'mealPreference' => $this->meal_preference,
            'allergies' => $this->allergies,
            'seatNumber' => $this->seat_number,
            'notes' => $this->notes,
            'code' => $isMainGuest ? $this->code : null,
            'menuSelected' => $this->getGuestMenuWithItems(),
            'invitationUrl' => $invitationUrl,
            'invitationQR' => $isMainGuest && $invitationUrl
                ? base64_encode(QrCode::format('png')->size(200)->generate($invitationUrl))
                : null,

            'companions' => $this->when(
                $isMainGuest,
                GuestResource::collection($this->companions)
            ),
            'invitations' => $this->when(
                $isMainGuest,
                GuestInvitationResource::collection($this->rsvpLogs)
            ),
            'rsvpLogs' => $this->when(
                $isMainGuest,
                GuestRsvpLogResource::collection($this->rsvpLogs)
            ),
        ];
    }

    private function getGuestMenuWithItems(): array
    {
        if (! $this->assigned_menu_id) {
            return [];
        }

        $menu = Menu::query()
            ->with('menuItems')
            ->where('id', $this->assigned_menu_id)
            ->where('event_id', $this->event_id)
            ->first();

        if (! $menu) {
            return [];
        }

        $groupedItems = $menu->menuItems->groupBy('type')->map(function ($items) {
            return $items->values()->toArray();
        });

        return [
            'menu' => $menu->toArray(),
            'menuItems' => $groupedItems,
        ];
    }
}
