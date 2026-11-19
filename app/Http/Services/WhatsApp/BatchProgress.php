<?php

namespace App\Http\Services\WhatsApp;

use App\Models\Events;
use App\Models\WhatsApp\Message;

/**
 * Progress of one send (docs/plans/guest-send-wizard-contract.md, 4.4), read from `whatsapp_messages.batch_id`.
 * Counts are exclusive: each message is in exactly one status. `processed` is what the progress bar uses (everything
 * that left the queue, delivered or failed); `delivered`/`read` arrive by webhook and are not waited for.
 */
class BatchProgress
{
    /** @return array<string, mixed>|null null when the event has no such batch (404 for the caller) */
    public function show(Events $event, string $batch, int $page, int $perPage): ?array
    {
        $base = Message::query()->where('event_id', $event->id)->where('batch_id', $batch);
        $counts = (clone $base)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $total = (int) $counts->sum();
        if ($total === 0) {
            return null;
        }

        $n = fn (string $s) => (int) ($counts[$s] ?? 0);
        $first = (clone $base)->orderBy('id')->first(['template_key', 'queued_at']);
        $paged = (clone $base)->with('guest:id,name')->orderBy('id')->paginate($perPage, ['*'], 'page', $page);

        return [
            'batchId' => $batch,
            'template' => $first->template_key,
            'startedAt' => $first->queued_at?->copy()->utc()->toIso8601String(),
            'total' => $total,
            'queued' => $n('queued'), 'sent' => $n('sent'), 'delivered' => $n('delivered'), 'read' => $n('read'), 'failed' => $n('failed'),
            // Reserved for the cancel phase (F5): always 0 until messages can be cancelled.
            'cancelled' => 0,
            'processed' => $total - $n('queued'),
            'done' => $n('queued') === 0,
            'messages' => $paged->getCollection()->map(fn (Message $m) => [
                'messageId' => $m->id, 'guestId' => $m->guest_id, 'guestName' => $m->guest?->name, 'status' => $m->status,
                'errorCode' => $m->error_code, 'errorMessage' => $m->error_message, 'retryCount' => (int) $m->retry_count,
            ])->values()->all(),
            'meta' => ['page' => $paged->currentPage(), 'perPage' => $paged->perPage(), 'total' => $paged->total()],
        ];
    }

    /** The newest batch of the event that still has queued messages, or null. */
    public function activeBatchId(Events $event): ?string
    {
        return Message::query()->where('event_id', $event->id)->whereNotNull('batch_id')->where('status', 'queued')->orderByDesc('id')->value('batch_id');
    }
}
