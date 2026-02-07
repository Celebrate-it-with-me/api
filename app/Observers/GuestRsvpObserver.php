<?php

namespace App\Observers;

use App\Events\EventNotificationEvent;
use App\Models\Guest;
use App\Support\Notifications\NotificationKeys;

class GuestRsvpObserver
{
    public function updated(Guest $guest): void
    {
        if (!$guest->wasChanged('rsvp_status')) {
            return;
        }

        $event = $guest->event;
        if (!$event) return;

        $ownerUserId = optional($event->userRoles->firstWhere(fn ($r) => $r->role?->name === 'owner'))->user?->id ?? 0;
        if ($ownerUserId <= 0) return;

        $key = match ($guest->rsvp_status) {
            'attending' => NotificationKeys::RSVP_CONFIRMED,
            'not-attending' => NotificationKeys::RSVP_DECLINED,
            default => null,
        };

        if (!$key) return;

        $actor = [
            'type' => 'guest',
            'id' => (int) $guest->id,
            'name' => $guest->name ?? 'Guest',
            'avatar_url' => null,
        ];

        EventNotificationEvent::dispatch(
            $key,
            (int) $guest->event_id,
            (int) $guest->id,
            $actor,
            (int) $ownerUserId,
            ['rsvp_status' => $guest->rsvp_status]
        );
    }
}
