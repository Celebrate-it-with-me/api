<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SuggestedMusicCreated
{
    use Dispatchable, SerializesModels;

    public int $eventId;
    public int $suggestionId;

    public array $actor;

    public string $songTitle;
    public string $artist;

    public int $ownerUserId;

    /**
     * Create a new event instance.
     */
    public function __construct(
        int $eventId,
        int $suggestionId,
        array $actor,
        string $songTitle,
        ?string $artist,
        int $ownerUserId
    )
    {
        $this->eventId = $eventId;
        $this->suggestionId = $suggestionId;
        $this->actor = $actor;
        $this->songTitle = $songTitle;
        $this->artist = $artist;
        $this->ownerUserId = $ownerUserId;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('channel-name'),
        ];
    }
}
