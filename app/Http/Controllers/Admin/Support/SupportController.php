<?php

namespace App\Http\Controllers\Admin\Support;

use App\Http\Controllers\Controller;
use App\Http\Services\Admin\AdminAudit;
use App\Models\SupportRequest;
use App\Models\SupportRequestNote;
use App\Models\User;
use App\Support\AdminPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The support inbox of the admin panel (A13): list, detail, status/assignment, internal notes. */
class SupportController extends Controller
{
    public function __construct(private readonly AdminAudit $audit) {}

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in(SupportRequest::STATUSES)],
            'assignedTo' => ['nullable', 'string', 'regex:/^(me|none|\d+)$/'],
            'eventId' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $base = SupportRequest::query()
            ->when($f['assignedTo'] ?? null, fn ($q, $v) => match ($v) {
                'me' => $q->where('assigned_to', $request->user()->id),
                'none' => $q->whereNull('assigned_to'),
                default => $q->where('assigned_to', (int) $v),
            })
            ->when($f['eventId'] ?? null, fn ($q, $v) => $q->where('event_id', $v))
            ->when($f['search'] ?? null, function ($q, $v) {
                $like = '%' . str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($v)) . '%';
                $q->where(fn ($w) => $w
                    ->whereRaw('LOWER(message) like ?', [$like])
                    ->orWhereHas('user', fn ($u) => $u->whereRaw('LOWER(name) like ?', [$like])->orWhereRaw('LOWER(email) like ?', [$like])));
            });

        // Counts per status ignore the status filter itself, so the tabs always show what each would hold.
        $counts = array_fill_keys(SupportRequest::STATUSES, 0);
        foreach ((clone $base)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status') as $status => $total) {
            $counts[$status] = (int) $total;
        }

        $rows = (clone $base)
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->with(['user:id,name,email', 'event:id,event_name', 'assignee:id,name'])
            ->withCount('notes')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($f['perPage'] ?? 25);

        return response()->json([
            'data' => $rows->getCollection()->map(fn (SupportRequest $r) => [
                'id' => $r->id,
                'message' => $r->message,
                'status' => $r->status,
                'user' => $this->person($r->user),
                'event' => $r->event ? ['id' => $r->event->id, 'name' => $r->event->event_name] : null,
                'assignedTo' => $r->assignee ? ['id' => $r->assignee->id, 'name' => $r->assignee->name] : null,
                'notesCount' => $r->notes_count,
                'createdAt' => $r->created_at?->toIso8601String(),
                'resolvedAt' => $r->resolved_at?->toIso8601String(),
            ])->values(),
            'meta' => [
                'page' => $rows->currentPage(), 'perPage' => $rows->perPage(), 'total' => $rows->total(), 'lastPage' => $rows->lastPage(),
                'counts' => $counts,
            ],
        ]);
    }

    public function show(SupportRequest $supportRequest): JsonResponse
    {
        return response()->json(['data' => $this->detail($supportRequest)]);
    }

    public function update(Request $request, SupportRequest $supportRequest): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(SupportRequest::STATUSES)],
            'assignedTo' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')->where(
                fn ($q) => $q->whereIn('id', User::role(AdminPermissions::STAFF_ROLES)->select('users.id'))
            )],
        ]);

        $before = ['status' => $supportRequest->status, 'assignedTo' => $supportRequest->assigned_to];

        if (array_key_exists('status', $data)) {
            $supportRequest->status = $data['status'];
            $supportRequest->resolved_at = $data['status'] === 'resolved' ? ($supportRequest->resolved_at ?? now()) : null;
        }
        if (array_key_exists('assignedTo', $data)) {
            $supportRequest->assigned_to = $data['assignedTo'];
        }

        if ($supportRequest->isDirty()) {
            $supportRequest->save();
            $this->audit->record('support.update', ['support_request', $supportRequest->id], [
                'event_id' => $supportRequest->event_id,
                'user_id' => $supportRequest->user_id,
                'before' => $before,
                'after' => ['status' => $supportRequest->status, 'assignedTo' => $supportRequest->assigned_to],
            ]);
        }

        return response()->json(['data' => $this->detail($supportRequest->refresh())]);
    }

    public function note(Request $request, SupportRequest $supportRequest): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'min:2', 'max:2000']]);

        $note = SupportRequestNote::create([
            'request_id' => $supportRequest->id,
            'author_id' => $request->user()->id,
            'body' => $data['body'],
        ]);

        $this->audit->record('support.note', ['support_request', $supportRequest->id], [
            'event_id' => $supportRequest->event_id,
            'user_id' => $supportRequest->user_id,
            'after' => ['noteId' => $note->id],
        ]);

        return response()->json(['data' => $this->noteRow($note->load('author:id,name'))], 201);
    }

    private function person(?User $u): ?array
    {
        return $u ? ['id' => $u->id, 'name' => $u->name, 'email' => $u->email] : null;
    }

    private function noteRow(SupportRequestNote $n): array
    {
        return [
            'id' => $n->id,
            'body' => $n->body,
            'author' => $n->author ? ['id' => $n->author->id, 'name' => $n->author->name] : null,
            'createdAt' => $n->created_at?->toIso8601String(),
        ];
    }

    private function detail(SupportRequest $r): array
    {
        $r->load(['user:id,name,email', 'event:id,event_name', 'assignee:id,name', 'notes.author:id,name']);

        return [
            'id' => $r->id,
            'message' => $r->message,
            'status' => $r->status,
            'context' => $r->context ?? (object) [],
            'user' => $r->user ? [...$this->person($r->user), 'adminPath' => "/users/{$r->user->id}"] : null,
            'event' => $r->event ? ['id' => $r->event->id, 'name' => $r->event->event_name, 'adminPath' => "/events/{$r->event->id}"] : null,
            'assignedTo' => $r->assignee ? ['id' => $r->assignee->id, 'name' => $r->assignee->name] : null,
            'notes' => $r->notes->map(fn ($n) => $this->noteRow($n))->values(),
            'createdAt' => $r->created_at?->toIso8601String(),
            'resolvedAt' => $r->resolved_at?->toIso8601String(),
        ];
    }
}
