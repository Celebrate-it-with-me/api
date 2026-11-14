<?php

namespace App\Http\Controllers\Admin\WhatsApp;

use App\Http\Controllers\Controller;
use App\Http\Requests\WhatsApp\AdminWhatsAppRequest;
use App\Http\Services\Admin\AdminAudit;
use App\Http\Services\WhatsApp\Presenter;
use App\Http\Services\WhatsApp\TemplateSync;
use App\Http\Services\WhatsApp\WhatsAppException;
use App\Http\Services\WhatsApp\WhatsAppService;
use App\Jobs\SendWhatsAppMessage;
use App\Models\WhatsApp\Message;
use App\Models\WhatsApp\Template;
use App\Support\WhatsApp\Phone;
use App\Support\WhatsApp\TemplateCatalog;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Admin WhatsApp console (docs/plans/communications-contract.md). Reads: admin.events.view. Writes: admin.whatsapp.manage + admin.reauth + reason, audited. */
class WhatsAppAdminController extends Controller
{
    public function __construct(private readonly WhatsAppService $whatsapp, private readonly AdminAudit $audit) {}

    public function status(): JsonResponse
    {
        $last = DB::table('whatsapp_inbound_events')->max('created_at');

        return response()->json(['data' => [
            'driver' => $this->whatsapp->driverName(),
            'configured' => $this->whatsapp->isAvailable(),
            'phoneNumberId' => Phone::tail(config('services.whatsapp.phone_number_id')),
            'businessAccountId' => Phone::tail(config('services.whatsapp.business_account_id')),
            'webhookUrl' => url('/api/v1/webhooks/whatsapp'),
            'verifyTokenSet' => (bool) config('services.whatsapp.verify_token'),
            'appSecretSet' => (bool) config('services.whatsapp.app_secret'),
            'lastWebhookAt' => $last ? Carbon::parse($last, 'UTC')->utc()->toIso8601String() : null,
        ]]);
    }

    public function summary(AdminWhatsAppRequest $request): JsonResponse
    {
        $f = $request->validated();
        $counts = Message::query()
            ->when($f['from'] ?? null, fn ($q, $d) => $q->where('queued_at', '>=', $d))
            ->when($f['to'] ?? null, fn ($q, $d) => $q->where('queued_at', '<=', $d))
            ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        $byStatus = collect(Message::STATUSES)->mapWithKeys(fn ($s) => [$s => (int) ($counts[$s] ?? 0)]);
        $total = (int) $counts->sum();
        $left = $total - $byStatus['queued'];

        // Of the messages that left our queue, the share WhatsApp delivered (0..1).
        return response()->json(['data' => ['total' => $total] + $byStatus->all()
            + ['deliveryRate' => $left > 0 ? round(($byStatus['delivered'] + $byStatus['read']) / $left, 4) : null]]);
    }

    public function messages(AdminWhatsAppRequest $request): JsonResponse
    {
        $f = $request->validated();
        $q = $f['q'] ?? null;

        $page = Message::query()->with(['event:id,event_name', 'guest:id,name'])
            ->when($f['status'] ?? null, fn ($b, $v) => $b->where('status', $v))
            ->when($f['event_id'] ?? null, fn ($b, $v) => $b->where('event_id', $v))
            ->when($f['template_key'] ?? null, fn ($b, $v) => $b->where('template_key', $v))
            ->when($f['from'] ?? null, fn ($b, $v) => $b->where('queued_at', '>=', $v))
            ->when($f['to'] ?? null, fn ($b, $v) => $b->where('queued_at', '<=', $v))
            ->when($q, function ($b) use ($q) {
                $like = '%' . addcslashes($q, '%_\\') . '%';
                $b->where(fn ($w) => $w->where('to_phone', 'like', $like)
                    ->orWhereIn('guest_id', DB::table('guests')->select('id')->where('name', 'ilike', $like)));
            })
            ->orderByDesc('id')->paginate($f['per_page'] ?? 25, ['*'], 'page', $f['page'] ?? 1);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Message $m) => Presenter::message($m, true))->values(),
            'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function templates(): JsonResponse
    {
        $rows = Template::query()->orderBy('id')->get()->map(fn (Template $t) => Presenter::template($t))->values();

        return response()->json(['data' => $rows]);
    }

    public function updateTemplate(AdminWhatsAppRequest $request, int $id): JsonResponse
    {
        $template = Template::query()->findOrFail($id);
        $data = $request->validated();

        if (isset($data['body']) && TemplateCatalog::placeholders($data['body']) !== TemplateCatalog::expectedMarkers($template->variables)) {
            throw ValidationException::withMessages(['body' => ['variables_changed']]);
        }

        $before = ['body' => $template->body, 'category' => $template->category, 'status' => $template->status];
        $template->fill(array_intersect_key($data, ['body' => 1, 'category' => 1]));
        if ($template->isDirty(['body', 'category']) && in_array($template->status, ['approved', 'rejected'], true)) {
            $template->status = 'draft';
            $template->approved_at = null;
            $template->rejection_reason = null;
        }
        $template->save();

        $this->audit->record('whatsapp.template_edit', ['whatsapp_template', $template->id], [
            'reason' => $data['reason'], 'before' => $before, 'after' => ['body' => $template->body, 'category' => $template->category, 'status' => $template->status, 'key' => $template->key, 'locale' => $template->locale],
        ]);

        return response()->json(['data' => Presenter::template($template)]);
    }

    public function submitTemplate(AdminWhatsAppRequest $request, int $id): JsonResponse
    {
        $template = Template::query()->findOrFail($id);
        $this->requireAvailable();

        try {
            $result = $this->whatsapp->gateway()->submitTemplate($template);
        } catch (WhatsAppException $e) {
            if ($e->retryable) {
                return $this->unavailable();
            }
            // Meta refused it outright: keep what it said so the admin can fix the text.
            $result = ['id' => $template->meta_template_id, 'status' => 'rejected', 'reason' => $e->getMessage()];
        }

        $template->forceFill([
            'meta_template_id' => $result['id'] ?? $template->meta_template_id, 'status' => $result['status'], 'submitted_at' => now(),
            'approved_at' => $result['status'] === 'approved' ? now() : null, 'rejection_reason' => $result['reason'] ?? null,
        ])->save();

        $this->audit->record('whatsapp.template_submit', ['whatsapp_template', $template->id], [
            'reason' => $request->validated()['reason'], 'after' => ['key' => $template->key, 'locale' => $template->locale, 'status' => $template->status],
        ]);

        return response()->json(['data' => Presenter::template($template)]);
    }

    public function syncTemplates(AdminWhatsAppRequest $request, TemplateSync $sync): JsonResponse
    {
        $this->requireAvailable();

        try {
            $sync->run();
        } catch (WhatsAppException) {
            return $this->unavailable();
        }

        $this->audit->record('whatsapp.template_sync', ['whatsapp_template', null], ['reason' => $request->validated()['reason']]);

        return $this->templates();
    }

    public function test(AdminWhatsAppRequest $request): JsonResponse
    {
        $data = $request->validated();
        $to = Phone::normalize($data['to']);
        if (! $to) {
            throw ValidationException::withMessages(['to' => ['invalid_phone']]);
        }

        $this->requireAvailable();
        $template = $this->whatsapp->sendable($data['template_key'], $data['locale']);
        if (! $template) {
            return response()->json(['message' => 'The WhatsApp template is not approved yet.', 'code' => 'template_not_approved'], 409);
        }

        $message = Message::create([
            'template_key' => $template->key, 'locale' => $template->locale, 'type' => 'template', 'to_phone' => $to,
            'params' => TemplateCatalog::SAMPLES, 'status' => 'queued', 'queued_at' => now(),
        ]);
        SendWhatsAppMessage::dispatch($message->id);

        $this->audit->record('whatsapp.test', ['whatsapp_message', $message->id], [
            'reason' => $data['reason'], 'after' => ['to' => Phone::mask($to), 'templateKey' => $template->key, 'locale' => $template->locale],
        ]);

        return response()->json(['data' => Presenter::message($message->refresh(), true)], 202);
    }

    private function requireAvailable(): void
    {
        if (! $this->whatsapp->isAvailable()) {
            throw new HttpResponseException($this->unavailable());
        }
    }

    private function unavailable(): JsonResponse
    {
        return response()->json(['message' => 'WhatsApp is not connected.', 'code' => 'whatsapp_unavailable'], 409);
    }
}
