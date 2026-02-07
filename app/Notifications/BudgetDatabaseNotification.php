<?php

namespace App\Notifications;

use App\Support\Notifications\NotificationEntities;
use App\Support\Notifications\NotificationKeys;
use App\Support\Notifications\NotificationMetaMap;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class BudgetDatabaseNotification extends Notification
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
            NotificationKeys::BUDGET_ITEM_CREATED => [
                'title' => 'Budget Item Created',
                'body' => "{$name} added a budget item: " . ($this->payload['item_title'] ?? ''),
            ],
            NotificationKeys::BUDGET_ITEM_PAID => [
                'title' => 'Budget Item Paid',
                'body' => "Budget item marked as paid: " . ($this->payload['item_title'] ?? ''),
            ],
            default => [
                'title' => 'Budget Notification',
                'body' => 'New activity in your event budget.',
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
                'type' => NotificationEntities::BUDGET_ITEM,
                'id' => $this->entityId,
            ],
            'message' => $message,
            'payload' => $this->payload,
            'action' => [
                'name' => 'View',
                'route' => [
                    'name' => 'event.budget',
                    'params' => ['eventId' => $this->eventId, 'budgetItemId' => $this->entityId],
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
