<?php

namespace App\Observers;

use App\Events\CommentCreated;
use App\Events\EventNotificationEvent;
use App\Support\Notifications\NotificationKeys;
use App\Models\EventComment;
use Illuminate\Support\Facades\Log;

class EventCommentObserver
{
    /**
     * Handle the SuggestedMusic "created" event.
     */
    public function created(EventComment $eventComment): void
    {
        $event = $eventComment->event;

        if (!$event) {
            return;
        }

        $ownerUserId = optional($event->userRoles->firstWhere(fn ($r) => $r->role?->name === 'owner'))->user?->id
            ?? 0;

        if ($ownerUserId <= 0) {
            return;
        }

        $actorModel = $eventComment->authorable;

        $actor = [
            'type' => $eventComment->authorable_type,
            'id' => (int) $eventComment->authorable_id,
            'name' => $actorModel?->name ?? 'guest',
            'avatar_url' => $actorModel?->avatar_url ?? null,
        ];

        CommentCreated::dispatch(
            (int) $eventComment->event_id,
            (int) $eventComment->id,
            $actor,
            (string) $eventComment->comment,
            (int) $ownerUserId
        );

    }

    /**
     * Handle the EventComment "updated" event.
     */
    public function updated(EventComment $eventComment): void
    {
        if (!$eventComment->wasChanged('comment')) {
            return;
        }

        $this->dispatchGeneric($eventComment, NotificationKeys::COMMENT_UPDATED);
    }

    /**
     * Handle the EventComment "deleted" event.
     */
    public function deleted(EventComment $eventComment): void
    {
        $this->dispatchGeneric($eventComment, NotificationKeys::COMMENT_DELETED);
    }

    protected function dispatchGeneric(EventComment $eventComment, string $key): void
    {
        $event = $eventComment->event;
        if (!$event) return;

        $ownerUserId = optional($event->userRoles->firstWhere(fn ($r) => $r->role?->name === 'owner'))->user?->id ?? 0;
        if ($ownerUserId <= 0) return;

        $actorModel = $eventComment->authorable;
        $actor = [
            'type' => $eventComment->authorable_type,
            'id' => (int) $eventComment->authorable_id,
            'name' => $actorModel?->name ?? 'guest',
            'avatar_url' => $actorModel?->avatar_url ?? null,
        ];

        EventNotificationEvent::dispatch(
            $key,
            (int) $eventComment->event_id,
            (int) $eventComment->id,
            $actor,
            (int) $ownerUserId
        );
    }

    /**
     * Handle the EventComment "restored" event.
     */
    public function restored(EventComment $eventComment): void
    {
        //
    }

    /**
     * Handle the EventComment "force deleted" event.
     */
    public function forceDeleted(EventComment $eventComment): void
    {
        //
    }
}
