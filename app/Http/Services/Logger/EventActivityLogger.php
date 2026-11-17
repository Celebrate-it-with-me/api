<?php

namespace App\Http\Services\Logger;

use App\Events\LogActivityEvent;
use App\Models\EventActivity;

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

    /**
     * Log an activity unless the same actor already did the same thing in this event in the last $seconds: guests
     * double-tap, retry and reload, and the feed must not repeat itself. Returns whether it was logged.
     */
    public static function logOnce(
        int $eventId,
        string $type,
        mixed $actor,
        mixed $target = null,
        array $payload = [],
        int $seconds = 60
    ): bool {
        $recent = EventActivity::query()
            ->where('event_id', $eventId)
            ->where('type', $type)
            ->where('actor_type', $actor?->getMorphClass())
            ->where('actor_id', $actor?->id)
            ->where('created_at', '>=', now()->subSeconds($seconds))
            ->exists();

        if ($recent) {
            return false;
        }

        self::log($eventId, $type, $actor, $target, $payload);

        return true;
    }
}
