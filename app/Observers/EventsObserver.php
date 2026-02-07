<?php

namespace App\Observers;

use App\Events\EventNotificationEvent;
use App\Models\Events;
use App\Support\Notifications\NotificationKeys;
use Illuminate\Support\Facades\Auth;

class EventsObserver
{
    public function created(Events $event): void
    {
        $this->dispatch($event, NotificationKeys::EVENT_CREATED);
    }

    public function updated(Events $event): void
    {
        if ($event->wasChanged(['event_name', 'start_date'])) {
            $this->dispatch($event, NotificationKeys::EVENT_UPDATED);
        }

        if ($event->wasChanged('status') && $event->status === 'published') {
            $this->dispatch($event, NotificationKeys::EVENT_PUBLISHED);
        }
    }

    protected function dispatch(Events $event, string $key): void
    {
        $ownerUserId = optional($event->userRoles->firstWhere(fn ($r) => $r->role?->name === 'owner'))->user?->id ?? 0;

        if ($ownerUserId <= 0) {
            return;
        }

        $user = Auth::user();
        $actor = [
            'type' => 'user',
            'id' => $user?->id ?? 0,
            'name' => $user?->name ?? 'System',
            'avatar_url' => $user?->avatar_url ?? null,
        ];

        EventNotificationEvent::dispatch(
            $key,
            (int) $event->id,
            (int) $event->id,
            $actor,
            (int) $ownerUserId,
            ['event_name' => $event->event_name]
        );
    }
}
