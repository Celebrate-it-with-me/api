<?php

namespace App\Http\Services\Logger;

use App\Events\LogActivityEvent;

class EventActivityLogger
{
    /**
     * Log an activity event.
     *
     * @param int $eventId
     * @param string $type
     * @param mixed|null $actor
     * @param mixed|null $target
     * @param array $payload
     * @return void
     */
    public static function log(
        int $eventId,
        string $type,
        mixed $actor = null,
        mixed $target = null,
        array $payload = []
    ): void
    {
        event(new LogActivityEvent($eventId, $type, $actor, $target, $payload));
    }
}
