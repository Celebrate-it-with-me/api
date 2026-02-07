<?php

namespace App\Notifications;

use App\Support\Notifications\NotificationEntities;
use App\Support\Notifications\NotificationKeys;
use App\Support\Notifications\NotificationMetaMap;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SuggestedMusicNotification extends Notification
{
    use Queueable;

    protected int $eventId;
    protected int $suggestionId;
    protected string $songTitle;
    protected ?string $artist;
    protected array $actor;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        int $eventId,
        int $suggestionId,
        string $songTitle,
        string $artist,
        array $actor
    )
    {
        $this->eventId = $eventId;
        $this->suggestionId = $suggestionId;
        $this->songTitle = $songTitle;
        $this->artist = $artist;
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
        $key = NotificationKeys::MUSIC_SUGGESTED;
        $meta = NotificationMetaMap::get($key);

        return [
            'version' => 1,
            'key' => $key,
            'event_id' => $this->eventId,

            'actor' => [
                'type' => $this->actor['type'],
                'id' => $this->actor['id'],
                'name' => $this->actor['name'],
                'avatar_url' => $this->actor['avatar_url'] ?? null,
            ],

            'entity' => [
                'type' => NotificationEntities::MUSIC_SUGGESTION,
                'id' => $this->suggestionId,
            ],

            'message' => [
                'title' => 'New song suggested',
                'body' => "{$this->actor['name']} suggested \"{$this->songTitle}\" by {$this->artist}.",
            ],

            'payload' => [
                'song_title' => $this->songTitle,
                'artist' => $this->artist ?? '',
            ],

            'action' => [
                'name' => 'Review',
                'route' => [
                    'name' => 'event.music', // frontend route name
                    'params' => [
                        'eventId' => $this->eventId,
                        'suggestionId' => $this->suggestionId,
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
