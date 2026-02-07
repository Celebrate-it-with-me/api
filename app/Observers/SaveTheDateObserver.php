<?php

namespace App\Observers;

use App\Events\EventNotificationEvent;
use App\Models\SaveTheDate;
use App\Support\Notifications\NotificationKeys;
use Illuminate\Support\Facades\Auth;

class SaveTheDateObserver
{
    public function updated(SaveTheDate $saveTheDate): void
    {
        if ($saveTheDate->wasChanged(['title', 'message', 'is_enabled'])) {
            $event = $saveTheDate->event;
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
                NotificationKeys::SAVE_THE_DATE_UPDATED,
                (int) $saveTheDate->event_id,
                (int) $saveTheDate->id,
                $actor,
                (int) $ownerUserId
            );
        }
    }
}
