<?php

namespace App\Notifications;

use App\Support\Notifications\NotificationEntities;
use App\Support\Notifications\NotificationKeys;
use App\Support\Notifications\NotificationMetaMap;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CommentCreatedNotification extends Notification
{
    use Queueable;

    protected int $eventId;
    protected int $commentId;
    protected string $comment;
    protected array $actor;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        int $eventId,
        int $commentId,
        string $comment,
        array $actor
    )
    {
        $this->eventId = $eventId;
        $this->commentId = $commentId;
        $this->comment = $comment;
        $this->actor = $actor;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(): array
    {
        $key = NotificationKeys::COMMENT_CREATED;
        $meta = NotificationMetaMap::get($key);

        $excerpt = mb_strlen($this->comment) > 120
            ? mb_substr($this->comment, 0, 120) . '…'
            : $this->comment;

        return [
            'version' => 1,
            'key' => $key,
            'event_id' => $this->eventId,

            'actor' => [
                'type' => $this->actor['type'],
                'id' => (int) $this->actor['id'],
                'name' => $this->actor['name'],
                'avatar_url' => $this->actor['avatar_url'] ?? null,
            ],

            'entity' => [
                'type' => NotificationEntities::COMMENT,
                'id' => $this->commentId,
            ],

            'message' => [
                'title' => 'New comment',
                'body' => "{$this->actor['name']} posted a new comment: \"{$excerpt}\"",
            ],

            'payload' => [
                'comment_excerpt' => $excerpt,
            ],

            'action' => [
                'name' => 'View',
                'route' => [
                    'name' => 'event.comments',
                    'params' => [
                        'eventId' => $this->eventId,
                        'commentId' => $this->commentId,
                    ],
                ],
            ],

            'meta' => [
                'priority' => $meta['priority'],
                'icon' => $meta['icon'],
                'color' => $meta['color'],
                'group_key' => null,
            ],
        ];
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            //
        ];
    }
}
