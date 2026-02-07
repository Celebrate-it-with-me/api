<?php

namespace App\Notifications;

use App\Support\Notifications\NotificationEntities;
use App\Support\Notifications\NotificationKeys;
use App\Support\Notifications\NotificationMetaMap;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * @deprecated Use module-scoped notifications instead: EventDatabaseNotification, LocationDatabaseNotification, etc.
 */
class DatabaseNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected string $key,
        protected int $eventId,
        protected ?int $entityId,
        protected array $actor,
        protected array $payload = []
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $meta = NotificationMetaMap::get($this->key);

        return [
            'version' => 1,
            'key' => $this->key,
            'event_id' => $this->eventId,
            'actor' => [
                'type' => $this->actor['type'],
                'id' => $this->actor['id'],
                'name' => $this->actor['name'],
                'avatar_url' => $this->actor['avatar_url'] ?? null,
            ],
            'entity' => [
                'type' => $this->resolveEntityType(),
                'id' => $this->entityId,
            ],
            'message' => $this->resolveMessage(),
            'payload' => $this->payload,
            'action' => $this->resolveAction(),
            'meta' => [
                'priority' => $meta['priority'],
                'icon' => $meta['icon'],
                'color' => $meta['color'],
                'group_key' => $this->payload['group_key'] ?? null,
            ],
        ];
    }

    protected function resolveEntityType(): string
    {
        return match ($this->key) {
            NotificationKeys::EVENT_CREATED,
            NotificationKeys::EVENT_UPDATED,
            NotificationKeys::EVENT_PUBLISHED => NotificationEntities::EVENT,

            NotificationKeys::LOCATION_CREATED,
            NotificationKeys::LOCATION_UPDATED,
            NotificationKeys::LOCATION_DELETED => NotificationEntities::LOCATION,

            NotificationKeys::RSVP_CONFIRMED,
            NotificationKeys::RSVP_DECLINED => NotificationEntities::RSVP,

            NotificationKeys::COMMENT_CREATED,
            NotificationKeys::COMMENT_UPDATED,
            NotificationKeys::COMMENT_DELETED => NotificationEntities::COMMENT,

            NotificationKeys::MUSIC_SUGGESTED,
            NotificationKeys::MUSIC_REACTED => NotificationEntities::MUSIC_SUGGESTION,

            NotificationKeys::BUDGET_ITEM_CREATED,
            NotificationKeys::BUDGET_ITEM_PAID => NotificationEntities::BUDGET_ITEM,

            NotificationKeys::SAVE_THE_DATE_UPDATED => NotificationEntities::SAVE_THE_DATE,

            default => 'unknown',
        };
    }

    protected function resolveMessage(): array
    {
        $name = $this->actor['name'];

        return match ($this->key) {
            NotificationKeys::EVENT_CREATED => [
                'title' => 'Event Created',
                'body' => "{$name} created a new event: " . ($this->payload['event_name'] ?? ''),
            ],
            NotificationKeys::EVENT_UPDATED => [
                'title' => 'Event Updated',
                'body' => "{$name} updated the event details.",
            ],
            NotificationKeys::EVENT_PUBLISHED => [
                'title' => 'Event Published',
                'body' => "The event is now published!",
            ],
            NotificationKeys::LOCATION_CREATED => [
                'title' => 'Location Added',
                'body' => "{$name} added a new location: " . ($this->payload['location_name'] ?? ''),
            ],
            NotificationKeys::LOCATION_UPDATED => [
                'title' => 'Location Updated',
                'body' => "{$name} updated the location: " . ($this->payload['location_name'] ?? ''),
            ],
            NotificationKeys::LOCATION_DELETED => [
                'title' => 'Location Deleted',
                'body' => "{$name} removed a location.",
            ],
            NotificationKeys::RSVP_CONFIRMED => [
                'title' => 'RSVP Confirmed',
                'body' => "{$name} confirmed attendance.",
            ],
            NotificationKeys::RSVP_DECLINED => [
                'title' => 'RSVP Declined',
                'body' => "{$name} declined attendance.",
            ],
            NotificationKeys::COMMENT_UPDATED => [
                'title' => 'Comment Updated',
                'body' => "{$name} updated a comment.",
            ],
            NotificationKeys::COMMENT_DELETED => [
                'title' => 'Comment Deleted',
                'body' => "{$name} deleted a comment.",
            ],
            NotificationKeys::MUSIC_REACTED => [
                'title' => 'Music Reaction',
                'body' => "{$name} " . ($this->payload['vote_type'] === 'up' ? 'upvoted' : 'downvoted') . " \"{$this->payload['song_title']}\".",
            ],
            NotificationKeys::BUDGET_ITEM_CREATED => [
                'title' => 'Budget Item Created',
                'body' => "{$name} added a budget item: " . ($this->payload['item_title'] ?? ''),
            ],
            NotificationKeys::BUDGET_ITEM_PAID => [
                'title' => 'Budget Item Paid',
                'body' => "Budget item marked as paid: " . ($this->payload['item_title'] ?? ''),
            ],
            NotificationKeys::SAVE_THE_DATE_UPDATED => [
                'title' => 'Save the Date Updated',
                'body' => "{$name} updated the Save the Date information.",
            ],
            default => [
                'title' => 'Notification',
                'body' => 'New activity in your event.',
            ],
        };
    }

    protected function resolveAction(): array
    {
        $route = match ($this->key) {
            NotificationKeys::LOCATION_CREATED,
            NotificationKeys::LOCATION_UPDATED,
            NotificationKeys::LOCATION_DELETED => [
                'name' => 'event.locations',
                'params' => ['eventId' => $this->eventId, 'locationId' => $this->entityId],
            ],
            NotificationKeys::RSVP_CONFIRMED,
            NotificationKeys::RSVP_DECLINED => [
                'name' => 'event.rsvp',
                'params' => ['eventId' => $this->eventId, 'rsvpId' => $this->entityId],
            ],
            NotificationKeys::COMMENT_UPDATED,
            NotificationKeys::COMMENT_DELETED => [
                'name' => 'event.comments',
                'params' => ['eventId' => $this->eventId, 'commentId' => $this->entityId],
            ],
            NotificationKeys::MUSIC_REACTED => [
                'name' => 'event.music',
                'params' => ['eventId' => $this->eventId, 'suggestionId' => $this->entityId],
            ],
            NotificationKeys::BUDGET_ITEM_CREATED,
            NotificationKeys::BUDGET_ITEM_PAID => [
                'name' => 'event.budget',
                'params' => ['eventId' => $this->eventId, 'budgetItemId' => $this->entityId],
            ],
            NotificationKeys::SAVE_THE_DATE_UPDATED => [
                'name' => 'event.save-the-date',
                'params' => ['eventId' => $this->eventId],
            ],
            default => [
                'name' => 'events.show',
                'params' => ['eventId' => $this->eventId],
            ],
        };

        return [
            'name' => 'View',
            'route' => $route,
        ];
    }
}
