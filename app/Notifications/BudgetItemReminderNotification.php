<?php

namespace App\Notifications;

use App\Models\BudgetItem;
use App\Models\Events;
use App\Support\Notifications\NotificationEntities;
use App\Support\Notifications\NotificationKeys;
use App\Support\Notifications\NotificationMetaMap;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BudgetItemReminderNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected mixed $budgetItems,
        protected Events $event
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toDatabase(object $notifiable): array
    {
        $key = NotificationKeys::BUDGET_ITEM_REMINDER;
        $meta = NotificationMetaMap::get($key);

        $count = count($this->budgetItems);
        $title = 'Payment reminders';
        $body = $count === 1
            ? "You have 1 budget item reminder for event \"{$this->event->event_name}\""
            : "You have {$count} budget item reminders for event \"{$this->event->event_name}\"";

        return [
            'version' => 1,
            'key' => $key,
            'event_id' => $this->event->id,
            'actor' => [
                'type' => 'system',
                'id' => 0,
                'name' => 'System',
                'avatar_url' => null,
            ],
            'entity' => [
                'type' => NotificationEntities::BUDGET,
                'id' => $this->event->id,
            ],
            'message' => [
                'title' => $title,
                'body' => $body,
            ],
            'payload' => [
                'items_count' => $count,
                'items' => collect($this->budgetItems)->map(fn($item) => [
                    'id' => $item['item']->id,
                    'title' => $item['item']->title,
                    'threshold' => $item['threshold'],
                    'due_date' => $item['item']->due_date->toDateTimeString(),
                ])->toArray(),
            ],
            'action' => [
                'name' => 'View',
                'route' => [
                    'name' => 'event.budget',
                    'params' => [
                        'eventId' => $this->event->id,
                    ],
                ],
            ],
            'meta' => [
                'priority' => $meta['priority'],
                'icon' => $meta['icon'],
                'color' => $meta['color'],
                'group_key' => "budget_reminder_event_{$this->event->id}",
            ],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = config('app.frontend_app.url') . "/events/{$this->event->id}/budget";

        return (new MailMessage)
            ->subject('Budget reminders: ' . $this->event->event_name)
            ->view('emails.budget-item-reminder', [
                'user' => $notifiable,
                'budgetItems' => $this->budgetItems,
                'event' => $this->event,
                'url' => $url,
            ]);
    }
}
