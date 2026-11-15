<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CommentCreated
{
    use Dispatchable, SerializesModels;

    public int $eventId;

    public int $commentId;

    public array $actor;

    public string $comment;

    public int $ownerUserId;

    public function __construct(
        int $eventId,
        int $commentId,
        array $actor,
        string $comment,
        int $ownerUserId
    ) {
        $this->eventId = $eventId;
        $this->commentId = $commentId;
        $this->actor = $actor;
        $this->comment = $comment;
        $this->ownerUserId = $ownerUserId;
    }
}
