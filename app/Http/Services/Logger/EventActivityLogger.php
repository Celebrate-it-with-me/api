<?php

namespace App\Http\Services\Logger;

use App\Events\LogActivityEvent;

class EventActivityLogger
{
    /**
     * Log an activity event.
     */
    public static function log(
        int $eventId,
        string $type,
        mixed $actor = null,
        mixed $target = null,
        array $payload = []
    ): void {
        event(new LogActivityEvent($eventId, $type, $actor, $target, $payload));
    }
}
