<?php

namespace App\Http\Controllers\Admin\Account;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Support\ClientInfo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Her own recent sign-ins and security changes, from the staff audit log. Only rows she is the actor of. */
class ActivityController extends Controller
{
    /** Keys of `after` that are safe to show back to her (never anything secret: the log does not hold any). */
    private const DETAIL_KEYS = ['method', 'device', 'step', 'count', 'sessionsClosed', 'remaining', 'language', 'name'];

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate(['perPage' => ['nullable', 'integer', 'min:5', 'max:50']]);

        $rows = AdminAuditLog::query()
            ->where('actor_id', $request->user()->id)
            ->where(fn ($q) => $q->where('action', 'like', 'auth.%')->orWhere('action', 'like', 'account.%'))
            ->orderByDesc('id')
            ->paginate($f['perPage'] ?? 20);

        return response()->json([
            'data' => $rows->getCollection()->map(fn (AdminAuditLog $r) => [
                'id' => $r->id,
                'action' => $r->action,
                'createdAt' => $r->created_at?->toIso8601String(),
                'ip' => ClientInfo::maskIp($r->ip),
                'device' => ClientInfo::device($r->user_agent),
                'detail' => array_intersect_key($r->after ?? [], array_flip(self::DETAIL_KEYS)),
            ])->values(),
            'meta' => ['page' => $rows->currentPage(), 'perPage' => $rows->perPage(), 'total' => $rows->total(), 'lastPage' => $rows->lastPage()],
        ]);
    }
}
