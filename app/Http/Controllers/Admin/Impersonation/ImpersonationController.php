<?php

namespace App\Http\Controllers\Admin\Impersonation;

use App\Http\Controllers\Controller;
use App\Http\Services\Admin\AdminAudit;
use App\Http\Services\Admin\Impersonation\ImpersonationService;
use App\Models\User;
use App\Support\AdminPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ImpersonationController extends Controller
{
    public function __construct(private readonly ImpersonationService $service) {}

    /** POST admin/users/{user}/impersonate */
    public function store(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'reason' => AdminAudit::reasonRule(),
            // It has to be a request of the same account: it is the justification that stays in the record.
            'supportRequestId' => ['nullable', 'integer', Rule::exists('support_requests', 'id')->where('user_id', $user->id)],
            'notify' => ['nullable', 'boolean'],
        ]);
        $staff = $request->user();
        $notify = (bool) ($data['notify'] ?? true);

        if (! $notify && ! $staff->hasRole(AdminPermissions::SUPERADMIN)) {
            return response()->json(['message' => 'Solo un superadmin puede entrar sin avisar a la usuaria.', 'code' => 'impersonation_notify_forbidden'], 403);
        }

        if ($refusal = $this->service->refusal($staff, $user, $staff->currentAccessToken())) {
            return response()->json(['message' => $refusal[1], 'code' => $refusal[0]], 422);
        }

        [$session, $token] = $this->service->start($staff, $user, $data['reason'], $data['supportRequestId'] ?? null, $notify);

        return response()->json([
            'token' => $token,
            'expiresAt' => $session->expires_at->toIso8601String(),
            'sessionId' => $session->id,
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
        ], 201);
    }
}
