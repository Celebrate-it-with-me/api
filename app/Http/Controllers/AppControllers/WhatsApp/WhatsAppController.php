<?php

namespace App\Http\Controllers\AppControllers\WhatsApp;

use App\Http\Controllers\Controller;
use App\Http\Requests\WhatsApp\SendWhatsAppRequest;
use App\Http\Services\WhatsApp\DailyLimit;
use App\Http\Services\WhatsApp\Presenter;
use App\Http\Services\WhatsApp\WhatsAppBroadcaster;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Events;
use App\Models\WhatsApp\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The organizer's WhatsApp sending (docs/plans/communications-contract.md). Routes carry `event.access` and `module.access:whatsapp`. */
class WhatsAppController extends Controller
{
    public function __construct(private readonly WhatsAppBroadcaster $broadcaster, private readonly DailyLimit $limits) {}

    public const MAX_RETRIES = 3;

    public function send(SendWhatsAppRequest $request, Events $event): JsonResponse
    {
        $data = $request->validated();
        $result = $this->broadcaster->send($event, $data['template'], $data['audience'], $data['guest_ids'] ?? null, (bool) ($data['force'] ?? false), (bool) ($data['dry_run'] ?? false), (bool) ($data['confirm_large'] ?? false));

        // A dry run only counts: nothing was queued.
        return response()->json(['data' => $result], ($data['dry_run'] ?? false) ? 200 : 202);
    }

    public function messages(Request $request, Events $event): JsonResponse
    {
        $f = $request->validate([
            'guest_id' => ['nullable', 'integer'], 'status' => ['nullable', 'in:' . implode(',', Message::STATUSES)],
            'latest_per_guest' => ['nullable', 'boolean'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = Message::query()->with('guest:id,name')->where('event_id', $event->id)
            ->when($f['guest_id'] ?? null, fn ($q, $id) => $q->where('guest_id', $id))
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($f['latest_per_guest'] ?? false, fn ($q) => $q->whereIn('id', Message::query()->selectRaw('max(id)')
                ->where('event_id', $event->id)->whereNotNull('guest_id')->groupBy('guest_id')))
            ->orderByDesc('id')->paginate($f['per_page'] ?? 25, ['*'], 'page', $f['page'] ?? 1);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Message $m) => Presenter::message($m))->values(),
            'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function summary(Events $event): JsonResponse
    {
        $counts = Message::query()->where('event_id', $event->id)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return response()->json(['data' => ['total' => (int) $counts->sum()] + collect(Message::STATUSES)->mapWithKeys(fn ($s) => [$s => (int) ($counts[$s] ?? 0)])->all()]);
    }

    public function retry(Events $event, int $message): JsonResponse
    {
        // Looked up inside the event (404 for anyone else's message); not route-bound so the impersonation guard answers first.
        $message = Message::query()->where('event_id', $event->id)->findOrFail($message);

        $refused = $this->limits->exclusive($event, function () use ($message) {
            // Re-read under the lock: a second click (or another tab) may have retried it already.
            $message->refresh();

            if ($message->status !== 'failed') {
                return response()->json(['message' => __('errors.whatsapp.only_failed_retry'), 'code' => 'not_failed'], 409);
            }
            if ($message->retry_count >= self::MAX_RETRIES) {
                return response()->json(['message' => __('errors.whatsapp.retry_limit'), 'code' => 'retry_limit'], 409);
            }
            if ($message->type === 'template' && $this->limits->remaining(Events::query()->findOrFail($message->event_id), $message->id) <= 0) {
                return response()->json(['message' => __('errors.whatsapp.daily_limit'), 'code' => 'whatsapp_daily_limit'], 429);
            }

            // Only a message still `failed` (with the count we read) is taken: the update is the atomic guard.
            $taken = Message::query()->whereKey($message->id)->where('status', 'failed')->where('retry_count', $message->retry_count)->update([
                'retry_count' => $message->retry_count + 1, 'send_started_at' => null, 'status' => 'queued', 'error_code' => null, 'error_message' => null, 'failed_at' => null, 'queued_at' => now(),
                'sent_at' => null, 'delivered_at' => null, 'read_at' => null, 'wa_message_id' => null,
            ]);

            return $taken === 1 ? null : response()->json(['message' => __('errors.whatsapp.only_failed_retry'), 'code' => 'not_failed'], 409);
        });

        if ($refused !== null) {
            return $refused;
        }

        SendWhatsAppMessage::dispatch($message->id);

        return response()->json(['data' => Presenter::message($message->refresh()->load(['guest:id,name']))], 202);
    }
}
