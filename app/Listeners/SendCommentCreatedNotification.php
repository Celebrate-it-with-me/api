<?php

namespace App\Listeners;

use App\Events\SuggestedMusicCreated;
use App\Models\User;
use App\Notifications\SuggestedMusicNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class SendCommentCreatedNotification
{
    /**
     * Create the event listener.
     */
    public function __construct() {}

    /**
     * Handle the event.
     */
    public function handle(SuggestedMusicCreated $event): void
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
            $event->suggestionId,
            $event->songTitle,
            $event->artist,
            $event->actor
        ));
    }
}
