<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EventNotificationEvent
{
    use Dispatchable, SerializesModels;

    public string $key;
    public int $eventId;
    public ?int $entityId;
    public array $actor;
    public int $ownerUserId;
    public array $payload;

    /**
     * Create a new event instance.
     */
    public function __construct(
        string $key,
        int $eventId,
        ?int $entityId,
        array $actor,
        int $ownerUserId,
        array $payload = []
    ) {
        $this->key = $key;
        $this->eventId = $eventId;
        $this->entityId = $entityId;
        $this->actor = $actor;
        $this->ownerUserId = $ownerUserId;
        $this->payload = $payload;
    }
}
