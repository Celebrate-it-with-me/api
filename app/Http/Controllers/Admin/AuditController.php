<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'actor' => ['nullable', 'integer'],
            'action' => ['nullable', 'string', 'max:80'],
            'event' => ['nullable', 'integer'],
            'user' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'perPage' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $rows = AdminAuditLog::query()
            ->with(['actor:id,name,email', 'affectedUser:id,name,email', 'event:id,event_name'])
            ->when($f['actor'] ?? null, fn ($q, $v) => $q->where('actor_id', $v))
            // `payment` matches payment.manual and payment.refund: an exact action, or a family by its prefix.
            ->when($f['action'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('action', $v)->orWhere('action', 'like', $v . '.%')))
            ->when($f['event'] ?? null, fn ($q, $v) => $q->where('event_id', $v))
            ->when($f['user'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v . ' 00:00:00'))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', $v . ' 23:59:59'))
            ->orderByDesc('id')
            ->paginate($f['perPage'] ?? 25);

        return response()->json([
            'data' => $rows->getCollection()->map(fn (AdminAuditLog $r) => [
                'id' => $r->id,
                'action' => $r->action,
                'createdAt' => $r->created_at?->toIso8601String(),
                'actor' => $r->actor ? ['id' => $r->actor->id, 'name' => $r->actor->name, 'email' => $r->actor->email] : null,
                'subjectType' => $r->subject_type,
                'subjectId' => $r->subject_id,
                'event' => $r->event ? ['id' => $r->event->id, 'name' => $r->event->event_name] : ($r->event_id ? ['id' => $r->event_id, 'name' => null] : null),
                'user' => $r->affectedUser ? ['id' => $r->affectedUser->id, 'name' => $r->affectedUser->name, 'email' => $r->affectedUser->email] : ($r->user_id ? ['id' => $r->user_id, 'name' => null, 'email' => null] : null),
                'reason' => $r->reason,
                'before' => $r->before,
                'after' => $r->after,
                'impersonatedBy' => $r->impersonated_by,
                'ip' => $r->ip,
            ])->values(),
            'meta' => ['page' => $rows->currentPage(), 'perPage' => $rows->perPage(), 'total' => $rows->total(), 'lastPage' => $rows->lastPage()],
        ]);
    }
}
