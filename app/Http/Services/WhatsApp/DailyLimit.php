<?php

namespace App\Http\Services\WhatsApp;

use App\Models\Events;
use App\Models\EventUserRole;
use App\Models\Guest;
use App\Models\WhatsApp\Message;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Daily cap of WhatsApp messages (audit W-01), per event and per account (the owner of the event), over a rolling 24 h.
 * Every queued message counts, forced resends and retries included. Config: services.whatsapp.max_daily_per_event
 * (default: 3 x guests with a phone, at least 100) and services.whatsapp.max_daily_per_account (default 1000).
 */
class DailyLimit
{
    /**
     * Runs `$callback` while holding the send lock of the event, so that reading the budget / recent sends and queueing
     * messages is one step: two simultaneous sends (or a send and a retry) can neither pass the cap together nor
     * duplicate a message. Waits up to 10 s; if the lock does not come, a 409 `send_in_progress`.
     *
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    public function exclusive(Events $event, \Closure $callback): mixed
    {
        try {
            return Cache::lock('whatsapp-send-event:' . $event->id, 30)->block((int) config('services.whatsapp.send_lock_wait', 10), $callback);
        } catch (LockTimeoutException) {
            throw new HttpResponseException(response()->json(['message' => __('errors.whatsapp.send_in_progress'), 'code' => 'send_in_progress'], 409));
        }
    }

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

    /** When the oldest message of the last 24 h of this event leaves the rolling window and frees one slot (null: none counted). */
    public function resetsAt(Events $event): ?Carbon
    {
        $oldest = Message::query()->where('event_id', $event->id)->where('type', 'template')
            ->where('queued_at', '>=', now()->subDay())->min('queued_at');

        return $oldest ? Carbon::parse($oldest, 'UTC')->addDay() : null;
    }
}
