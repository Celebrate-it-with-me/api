<?php

namespace App\Http\Services\WhatsApp;

use App\Models\Events;
use App\Models\Guest;
use App\Models\WhatsApp\Message;
use Illuminate\Support\Collection;

/**
 * Where each family stands with the invitation, in one place for the roster, the audience and the recipients list
 * (docs/plans/guest-send-wizard-contract.md, 4.8). State: the latest template message of the family, or
 * `opted_out`, `manual` (marked as shared by hand), `no_phone` or `none`. Only main guests are messaged.
 */
class DeliveryTracker
{
    public const STATES = ['none', 'manual', 'queued', 'sent', 'delivered', 'read', 'failed', 'opted_out', 'no_phone'];

    /** @return array<int, array<string, mixed>> delivery by main guest id, for every family of the event */
    public function forEvent(Events $event): array
    {
        $guests = Guest::query()->where('event_id', $event->id)->whereNull('parent_id')
            ->get(['id', 'phone', 'whatsapp_opted_out_at', 'invitation_shared_at']);

        return $this->build($event, $guests);
    }

    /** @param Collection<int, Guest> $guests main guests @return array<int, array<string, mixed>> */
    public function forGuests(Events $event, Collection $guests): array
    {
        return $this->build($event, $guests);
    }

    /** Counters for the roster chips. @param array<int, array<string, mixed>> $map @return array<string, int> */
    public function counts(array $map): array
    {
        $by = array_count_values(array_column($map, 'state'));
        $n = fn (string $state) => (int) ($by[$state] ?? 0);

        return [
            'unsent' => $n('none'), 'queued' => $n('queued'), 'sent' => $n('sent'), 'delivered' => $n('delivered'), 'read' => $n('read'),
            'failed' => $n('failed'), 'noPhone' => $n('no_phone'), 'optedOut' => $n('opted_out'),
            'shared' => count(array_filter($map, fn (array $d) => $d['sharedAt'] !== null)),
        ];
    }

    /** Ids of the families in a chip filter. @param array<int, array<string, mixed>> $map @return list<int> */
    public function idsFor(array $map, string $filter): array
    {
        $states = match ($filter) {
            'unsent' => ['none'],
            'sent' => ['manual', 'queued', 'sent', 'delivered', 'read'],
            'problem' => ['failed', 'opted_out', 'no_phone'],
            default => null,
        };

        if ($states === null) {
            return array_map('intval', array_keys($map));
        }

        return array_map('intval', array_keys(array_filter($map, fn (array $d) => in_array($d['state'], $states, true))));
    }

    /** @param Collection<int, Guest> $guests @return array<int, array<string, mixed>> */
    private function build(Events $event, Collection $guests): array
    {
        $ids = $guests->pluck('id')->all();
        $latest = $ids === [] ? collect() : Message::query()
            ->where('event_id', $event->id)->where('type', 'template')
            ->whereIn('id', Message::query()->selectRaw('max(id)')->where('event_id', $event->id)->where('type', 'template')
                ->whereIn('guest_id', $ids)->groupBy('guest_id'))
            ->get()->keyBy('guest_id');

        $map = [];
        foreach ($guests as $guest) {
            $message = $latest->get($guest->id);
            $shared = $guest->invitation_shared_at;

            $state = match (true) {
                $guest->whatsapp_opted_out_at !== null => 'opted_out',
                $message !== null => $message->status,
                $shared !== null => 'manual',
                trim((string) $guest->phone) === '' => 'no_phone',
                default => 'none',
            };

            $map[$guest->id] = [
                'state' => $state,
                'lastMessageId' => $message?->id,
                'lastAt' => $message ? $this->at($message)?->copy()->utc()->toIso8601String() : null,
                'errorCode' => $message?->error_code,
                'errorMessage' => $message?->error_message,
                'retryCount' => (int) ($message?->retry_count ?? 0),
                'sharedAt' => $shared?->copy()->utc()->toIso8601String(),
            ];
        }

        return $map;
    }

    /** The moment the message reached its current state. */
    private function at(Message $m)
    {
        return match ($m->status) {
            'read' => $m->read_at,
            'delivered' => $m->delivered_at,
            'sent' => $m->sent_at,
            'failed' => $m->failed_at,
            default => $m->queued_at,
        } ?? $m->queued_at;
    }
}
