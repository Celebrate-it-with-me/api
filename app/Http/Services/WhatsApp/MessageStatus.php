<?php

namespace App\Http\Services\WhatsApp;

use App\Models\WhatsApp\Message;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** The only place a message's status changes. It only moves forward: a late `sent` never overwrites a `read`. */
class MessageStatus
{
    /** queued -> sent: only if it is still queued (a fast webhook may have got there first). */
    public function sent(Message $message, string $waMessageId, ?Carbon $at = null): void
    {
        $at ??= now();
        Message::query()->whereKey($message->id)->where('status', 'queued')
            ->update(['status' => 'sent', 'wa_message_id' => $waMessageId, 'sent_at' => $at, 'updated_at' => now()]);
        // Keep the id even if the status was already ahead of us.
        Message::query()->whereKey($message->id)->whereNull('wa_message_id')->update(['wa_message_id' => $waMessageId]);
        $message->refresh();
        $this->applyPending($waMessageId);
    }

    /** Delivery updates that reached us before the id was stored (audit W-09) are applied now, oldest first. */
    public function applyPending(string $waMessageId): void
    {
        $rows = DB::table('whatsapp_pending_statuses')->where('wa_message_id', $waMessageId)->orderBy('id')->get();
        if ($rows->isEmpty()) {
            return;
        }
        $message = Message::query()->where('wa_message_id', $waMessageId)->first();
        if (! $message) {
            return;
        }
        foreach ($rows as $row) {
            $st = json_decode($row->payload, true) ?: [];
            $at = isset($st['timestamp']) ? Carbon::createFromTimestampUTC((int) $st['timestamp']) : null;
            match ($row->status) {
                'sent', 'delivered', 'read' => $this->advance($message, $row->status, $at),
                'failed' => $this->fail($message, (string) ($st['errors'][0]['code'] ?? 'failed'), (string) ($st['errors'][0]['message'] ?? $st['errors'][0]['title'] ?? 'Delivery failed'), $at),
                default => null,
            };
        }
        DB::table('whatsapp_pending_statuses')->where('wa_message_id', $waMessageId)->whereIn('id', $rows->pluck('id'))->delete();
    }

    /** @param 'sent'|'delivered'|'read' $status */
    public function advance(Message $message, string $status, ?Carbon $at = null): void
    {
        DB::transaction(function () use ($message, $status, $at) {
            $m = Message::query()->lockForUpdate()->find($message->id);
            $at ??= now();
            $ranks = Message::RANK;
            $changes = [];

            // Fill the timestamp of this step and of the steps before it that we never heard about.
            foreach (['sent' => 'sent_at', 'delivered' => 'delivered_at', 'read' => 'read_at'] as $step => $column) {
                if ($ranks[$step] <= $ranks[$status] && $m->$column === null) {
                    $changes[$column] = $at;
                }
            }
            if (($ranks[$m->status] ?? -1) < $ranks[$status] && $m->status !== 'failed') {
                $changes['status'] = $status;
            } elseif ($m->status === 'failed' && $m->wa_message_id && $status !== 'sent') {
                // WhatsApp says it was delivered/read after all: the failure was ours, not theirs.
                $changes += ['status' => $status, 'error_code' => null, 'error_message' => null, 'failed_at' => null];
            }

            if ($changes) {
                $m->forceFill($changes)->save();
            }
        });
    }

    public function fail(Message $message, string $code, string $text, ?Carbon $at = null): void
    {
        Message::query()->whereKey($message->id)->whereIn('status', ['queued', 'sent'])->update([
            'status' => 'failed', 'error_code' => mb_substr($code, 0, 40), 'error_message' => mb_substr($text, 0, 500),
            'failed_at' => $at ?? now(), 'updated_at' => now(),
        ]);
    }
}
