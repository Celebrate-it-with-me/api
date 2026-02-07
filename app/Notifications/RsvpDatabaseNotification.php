<?php

namespace App\Notifications;

use App\Support\Notifications\NotificationEntities;
use App\Support\Notifications\NotificationKeys;
use App\Support\Notifications\NotificationMetaMap;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class RsvpDatabaseNotification extends Notification
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
            NotificationKeys::RSVP_CONFIRMED => [
                'title' => 'RSVP Confirmed',
                'body' => "{$name} confirmed attendance.",
            ],
            NotificationKeys::RSVP_DECLINED => [
                'title' => 'RSVP Declined',
                'body' => "{$name} declined attendance.",
            ],
            default => [
                'title' => 'RSVP Notification',
                'body' => 'New RSVP activity.',
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
                'type' => NotificationEntities::RSVP,
                'id' => $this->entityId,
            ],
            'message' => $message,
            'payload' => $this->payload,
            'action' => [
                'name' => 'View',
                'route' => [
                    'name' => 'event.rsvp',
                    'params' => ['eventId' => $this->eventId, 'rsvpId' => $this->entityId],
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
