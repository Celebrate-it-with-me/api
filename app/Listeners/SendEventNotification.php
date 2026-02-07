<?php

namespace App\Listeners;

use App\Events\EventNotificationEvent;
use App\Models\User;
use App\Support\Notifications\NotificationKeys;
use App\Notifications\EventDatabaseNotification;
use App\Notifications\LocationDatabaseNotification;
use App\Notifications\RsvpDatabaseNotification;
use App\Notifications\CommentDatabaseNotification;
use App\Notifications\MusicDatabaseNotification;
use App\Notifications\BudgetDatabaseNotification;
use App\Notifications\SaveTheDateDatabaseNotification;

class SendEventNotification
{
    /**
     * Handle the event.
     */
    public function handle(EventNotificationEvent $event): void
    {
        $owner = User::find($event->ownerUserId);

        if (!$owner) {
            return;
        }

        // Do not notify the same user who performed the action
        if ($event->actor['type'] === 'user' && (int)$event->actor['id'] === (int)$owner->id) {
            return;
        }

        $notificationClass = $this->resolveNotificationClass($event->key);

        if (!$notificationClass) {
            return;
        }

        $owner->notify(new $notificationClass(
            $event->key,
            $event->eventId,
            $event->entityId,
            $event->actor,
            $event->payload
        ));
    }

    protected function resolveNotificationClass(string $key): ?string
    {
        return match ($key) {
            NotificationKeys::EVENT_CREATED,
            NotificationKeys::EVENT_UPDATED,
            NotificationKeys::EVENT_PUBLISHED => EventDatabaseNotification::class,

            NotificationKeys::LOCATION_CREATED,
            NotificationKeys::LOCATION_UPDATED,
            NotificationKeys::LOCATION_DELETED => LocationDatabaseNotification::class,

            NotificationKeys::RSVP_CONFIRMED,
            NotificationKeys::RSVP_DECLINED => RsvpDatabaseNotification::class,

            NotificationKeys::COMMENT_CREATED,
            NotificationKeys::COMMENT_UPDATED,
            NotificationKeys::COMMENT_DELETED => CommentDatabaseNotification::class,

            NotificationKeys::MUSIC_SUGGESTED,
            NotificationKeys::MUSIC_REACTED => MusicDatabaseNotification::class,

            NotificationKeys::BUDGET_ITEM_CREATED,
            NotificationKeys::BUDGET_ITEM_PAID => BudgetDatabaseNotification::class,

            NotificationKeys::SAVE_THE_DATE_UPDATED => SaveTheDateDatabaseNotification::class,

            default => null,
        };
    }
}
