<?php

namespace App\Notifications;

use App\Support\Notifications\NotificationEntities;
use App\Support\Notifications\NotificationKeys;
use App\Support\Notifications\NotificationMetaMap;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class EventDatabaseNotification extends Notification
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
        $name = $this->actor['name'];

        $message = match ($this->key) {
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
            default => [
                'title' => 'Event Notification',
                'body' => 'New activity in your event.',
            ],
        };

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
                'type' => NotificationEntities::EVENT,
                'id' => $this->entityId,
            ],
            'message' => $message,
            'payload' => $this->payload,
            'action' => [
                'name' => 'View',
                'route' => [
                    'name' => 'events.show',
                    'params' => ['eventId' => $this->eventId],
                ],
            ],
            'meta' => [
                'priority' => $meta['priority'],
                'icon' => $meta['icon'],
                'color' => $meta['color'],
                'group_key' => $this->payload['group_key'] ?? null,
            ],
        ];
    }
}
