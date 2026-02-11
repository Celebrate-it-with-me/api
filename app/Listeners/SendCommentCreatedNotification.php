<?php

namespace App\Listeners;

use App\Events\CommentCreated;
use App\Models\User;
use App\Notifications\CommentCreatedNotification;

class SendCommentCreatedNotification
{
    /**
     * Create the event listener.
     */
    public function __construct() {}

    /**
     * Handle the event.
     */
    public function handle(CommentCreated $event): void
    {
        $owner = User::query()->find($event->ownerUserId);

        if (!$owner) {
            return;
        }

        if ($event->actor['type'] === 'user' && (int)$event->actor['id'] === $owner->id) {
            return;
        }

        $owner->notify(new CommentCreatedNotification(
            $event->eventId,
            $event->commentId,
            $event->comment,
            $event->actor
        ));
    }
}
