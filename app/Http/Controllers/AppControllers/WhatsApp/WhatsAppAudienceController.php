<?php

namespace App\Http\Controllers\AppControllers\WhatsApp;

use App\Http\Controllers\Controller;
use App\Http\Services\WhatsApp\SendAudience;
use App\Models\Events;
use App\Support\WhatsApp\TemplateCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Read-only side of the send wizard (docs/plans/guest-send-wizard-contract.md, 4.1 and 4.2). Routes carry
 * `event.access` and `module.access:guests` (not `whatsapp`): the manual send, on the free plan, needs them too.
 */
class WhatsAppAudienceController extends Controller
{
    public function __construct(private readonly SendAudience $audience) {}

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
}
