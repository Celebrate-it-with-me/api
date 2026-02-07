<?php

namespace App\Notifications;

use App\Support\Notifications\NotificationEntities;
use App\Support\Notifications\NotificationKeys;
use App\Support\Notifications\NotificationMetaMap;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class LocationDatabaseNotification extends Notification
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
            default => [
                'title' => 'Location Notification',
                'body' => 'New activity in your event locations.',
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
                'type' => NotificationEntities::LOCATION,
                'id' => $this->entityId,
            ],
            'message' => $message,
            'payload' => $this->payload,
            'action' => [
                'name' => 'View',
                'route' => [
                    'name' => 'event.locations',
                    'params' => ['eventId' => $this->eventId, 'locationId' => $this->entityId],
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
