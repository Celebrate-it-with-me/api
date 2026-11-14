<?php

namespace App\Http\Services\WhatsApp;

use App\Models\Events;
use App\Models\EventUserRole;
use App\Models\Guest;
use App\Models\WhatsApp\Message;

/**
 * Daily cap of WhatsApp messages (audit W-01), per event and per account (the owner of the event), over a rolling 24 h.
 * Every queued message counts, forced resends and retries included. Config: services.whatsapp.max_daily_per_event
 * (default: 3 x guests with a phone, at least 100) and services.whatsapp.max_daily_per_account (default 1000).
 */
class DailyLimit
{
    public function eventLimit(Events $event): int
    {
        $configured = config('services.whatsapp.max_daily_per_event');
        if ($configured !== null && $configured !== '') {
            return (int) $configured;
        }
        $withPhone = Guest::query()->where('event_id', $event->id)->whereNull('parent_id')->whereNotNull('phone')->where('phone', '!=', '')->count();

        return max(100, 3 * $withPhone);
    }

    public function accountLimit(): int
    {
        return (int) config('services.whatsapp.max_daily_per_account', 1000);
    }

    /** How many more messages may be queued now for this event, counting both caps. */
    public function remaining(Events $event, ?int $exceptMessageId = null): int
    {
        $since = now()->subDay();
        $count = fn ($q) => $q->where('queued_at', '>=', $since)->when($exceptMessageId, fn ($q) => $q->where('id', '!=', $exceptMessageId));

        $forEvent = $count(Message::query()->where('event_id', $event->id)->where('type', 'template'))->count();

        $ownerId = EventUserRole::query()->where('event_id', $event->id)->whereHas('role', fn ($q) => $q->where('name', 'owner'))->value('user_id');
        $eventIds = $ownerId
            ? EventUserRole::query()->where('user_id', $ownerId)->whereHas('role', fn ($q) => $q->where('name', 'owner'))->pluck('event_id')->all()
            : [$event->id];
        $forAccount = $count(Message::query()->whereIn('event_id', $eventIds)->where('type', 'template'))->count();

        return max(0, min($this->eventLimit($event) - $forEvent, $this->accountLimit() - $forAccount));
    }
}
