<?php

namespace App\Observers;

use App\Events\EventNotificationEvent;
use App\Models\EventLocation;
use App\Support\Notifications\NotificationKeys;
use Illuminate\Support\Facades\Auth;

class EventLocationObserver
{
    public function created(EventLocation $location): void
    {
        $this->dispatch($location, NotificationKeys::LOCATION_CREATED);
    }

    public function updated(EventLocation $location): void
    {
        if ($location->wasChanged(['name', 'address', 'city', 'state'])) {
            $this->dispatch($location, NotificationKeys::LOCATION_UPDATED);
        }
    }

    public function deleted(EventLocation $location): void
    {
        $this->dispatch($location, NotificationKeys::LOCATION_DELETED);
    }

    protected function dispatch(EventLocation $location, string $key): void
    {
        $event = $location->event;
        if (!$event) return;

        $ownerUserId = optional($event->userRoles->firstWhere(fn ($r) => $r->role?->name === 'owner'))->user?->id ?? 0;
        if ($ownerUserId <= 0) return;

        $user = Auth::user();
        $actor = [
            'type' => 'user',
            'id' => $user?->id ?? 0,
            'name' => $user?->name ?? 'System',
            'avatar_url' => $user?->avatar_url ?? null,
        ];

        EventNotificationEvent::dispatch(
            $key,
            (int) $location->event_id,
            (int) $location->id,
            $actor,
            (int) $ownerUserId,
            ['location_name' => $location->name]
        );
    }
}
