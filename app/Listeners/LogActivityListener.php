<?php

namespace App\Listeners;

use App\Events\LogActivityEvent;
use App\Models\EventActivity;

class LogActivityListener
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(LogActivityEvent $event): void
    {
        EventActivity::logActivity(
            $event->eventId,
            $event->type,
            $event->actor,
            $event->target,
            $event->payload
        );
    }
}
