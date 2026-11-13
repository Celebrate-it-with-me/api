<?php

namespace App\Http\Controllers\AppControllers\Support;

use App\Http\Controllers\Controller;
use App\Mail\SupportRequestMail;
use App\Models\EventUserRole;
use App\Models\SupportRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/** The organizer's side of "Necesito ayuda": send a request, list your own. */
class SupportRequestController extends Controller
{
    /** Context keys the app may attach; anything else is rejected. */
    public const CONTEXT_KEYS = ['screen', 'route', 'eventId', 'eventName', 'plan', 'browser', 'language', 'viewport'];

    public function index(Request $request): JsonResponse
    {
        $rows = SupportRequest::query()
            ->where('user_id', $request->user()->id)
            ->with('event:id,event_name')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json(['data' => $rows->map(fn (SupportRequest $r) => [
            'id' => $r->id,
            'message' => $r->message,
            'status' => $r->status,
            'event' => $r->event ? ['id' => $r->event->id, 'name' => $r->event->event_name] : null,
            'createdAt' => $r->created_at?->toIso8601String(),
            'resolvedAt' => $r->resolved_at?->toIso8601String(),
        ])->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'message' => ['required', 'string', 'min:5', 'max:2000'],
            'eventId' => [
                'nullable', 'integer',
                Rule::exists(EventUserRole::class, 'event_id')->where('user_id', $user->id),
            ],
            'context' => ['nullable', 'array', function ($attr, $value, $fail) {
                if (array_diff(array_keys($value), self::CONTEXT_KEYS)) {
                    $fail('El contexto contiene claves no permitidas.');
                }
                if (strlen((string) json_encode($value)) > 2000) {
                    $fail('El contexto es demasiado grande.');
                }
            }],
            'context.*' => ['nullable', function ($attr, $value, $fail) {
                if ($value !== null && (! is_scalar($value) || strlen((string) $value) > 300)) {
                    $fail('Un valor del contexto no es válido.');
                }
            }],
        ]);

        $supportRequest = SupportRequest::create([
            'user_id' => $user->id,
            'event_id' => $data['eventId'] ?? null,
            'message' => $data['message'],
            'context' => $data['context'] ?? null,
            'status' => 'new',
        ]);

        try {
            Mail::to(config('services.support.email'))->send(new SupportRequestMail($supportRequest->load(['user', 'event'])));
        } catch (\Throwable $e) {
            // The request is saved and shows in the inbox; a mail failure must not lose it for the user.
            report($e);
        }

        return response()->json(['id' => $supportRequest->id], 201);
    }
}
