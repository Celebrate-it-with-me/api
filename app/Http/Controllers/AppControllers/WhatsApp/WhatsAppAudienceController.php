<?php

namespace App\Http\Controllers\AppControllers\WhatsApp;

use App\Http\Controllers\Controller;
use App\Http\Services\WhatsApp\BatchProgress;
use App\Http\Services\WhatsApp\SendAudience;
use App\Http\Services\WhatsApp\SendPreview;
use App\Models\Events;
use App\Models\Guest;
use App\Support\WhatsApp\TemplateCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Read-only side of the send wizard (docs/plans/guest-send-wizard-contract.md, 4.1 and 4.2). Routes carry
 * `event.access` and `module.access:guests` (not `whatsapp`): the manual send, on the free plan, needs them too.
 */
class WhatsAppAudienceController extends Controller
{
    public function __construct(private readonly SendAudience $audience, private readonly SendPreview $preview, private readonly BatchProgress $batches) {}

    public function audience(Request $request, Events $event): JsonResponse
    {
        $f = $request->validate(['template' => ['nullable', Rule::in(TemplateCatalog::SENDABLE_KEYS)]]);

        return response()->json(['data' => $this->audience->audience($event, $f['template'] ?? 'invitation')]);
    }

    public function recipients(Request $request, Events $event): JsonResponse
    {
        $f = $request->validate([
            'template' => ['nullable', Rule::in(TemplateCatalog::SENDABLE_KEYS)],
            'category' => ['nullable', Rule::in(SendAudience::CATEGORIES)],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json($this->audience->recipients(
            $event, $f['template'] ?? 'invitation', $f['category'] ?? 'all', $f['search'] ?? null, (int) ($f['page'] ?? 1), (int) ($f['per_page'] ?? 50),
        ));
    }

    /** The message of step 2, rendered for one guest (4.3). Nothing is queued or counted. */
    public function preview(Request $request, Events $event): JsonResponse
    {
        $f = $request->validate([
            'template' => ['required', Rule::in(TemplateCatalog::SENDABLE_KEYS)],
            'guest_id' => ['nullable', 'integer'],
            'locale' => ['nullable', Rule::in(TemplateCatalog::LOCALES)],
        ]);

        // Main guests of this event only: anybody else's id is a plain 404.
        $guest = isset($f['guest_id']) ? Guest::query()->where('event_id', $event->id)->whereNull('parent_id')->findOrFail($f['guest_id']) : null;

        return response()->json(['data' => $this->preview->render($event, $f['template'], $guest, $f['locale'] ?? null)]);
    }

    /** Progress of one send (4.4). */
    public function batch(Request $request, Events $event, string $batch): JsonResponse
    {
        $f = $request->validate(['page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        // Checked here, not in the route, so an anonymous caller still gets 401 first.
        abort_unless(Str::isUuid($batch), 404);
        $data = $this->batches->show($event, $batch, (int) ($f['page'] ?? 1), (int) ($f['per_page'] ?? 100));
        abort_if($data === null, 404);

        return response()->json(['data' => $data]);
    }

    /** The send still in progress, or `data: null` (4.4): lets the banner and the wizard survive a reload. */
    public function activeBatch(Request $request, Events $event): JsonResponse
    {
        $f = $request->validate(['page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $id = $this->batches->activeBatchId($event);

        return response()->json(['data' => $id ? $this->batches->show($event, $id, (int) ($f['page'] ?? 1), (int) ($f['per_page'] ?? 100)) : null]);
    }
}
