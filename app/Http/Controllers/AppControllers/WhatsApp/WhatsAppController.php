<?php

namespace App\Http\Controllers\AppControllers\WhatsApp;

use App\Http\Controllers\Controller;
use App\Http\Requests\WhatsApp\SendWhatsAppRequest;
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
    public function __construct(private readonly WhatsAppBroadcaster $broadcaster) {}

    public function send(SendWhatsAppRequest $request, Events $event): JsonResponse
    {
        $data = $request->validated();
        $result = $this->broadcaster->send($event, $data['template'], $data['audience'], $data['guest_ids'] ?? null, (bool) ($data['force'] ?? false), (bool) ($data['dry_run'] ?? false));

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

        if ($message->status !== 'failed') {
            return response()->json(['message' => 'Only failed messages can be retried.', 'code' => 'not_failed'], 409);
        }

        $message->forceFill([
            'status' => 'queued', 'error_code' => null, 'error_message' => null, 'failed_at' => null, 'queued_at' => now(),
            'sent_at' => null, 'delivered_at' => null, 'read_at' => null, 'wa_message_id' => null,
        ])->save();
        SendWhatsAppMessage::dispatch($message->id);

        return response()->json(['data' => Presenter::message($message->refresh()->load(['guest:id,name']))], 202);
    }
}
