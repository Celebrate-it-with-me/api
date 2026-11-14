<?php

namespace App\Http\Controllers\Admin\System;

use App\Http\Controllers\Controller;
use App\Http\Services\Admin\AdminAudit;
use App\Http\Services\Admin\System\TwoFactorEnforcement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The "require two-factor for all staff" switch (`admin.security.manage`, superadmin only). */
class SecurityController extends Controller
{
    public function show(TwoFactorEnforcement $enforcement): JsonResponse
    {
        return response()->json($enforcement->status());
    }

    public function update(Request $request, TwoFactorEnforcement $enforcement, AdminAudit $audit): JsonResponse
    {
        $f = $request->validate([
            'enabled' => ['required', 'boolean'],
            'reason' => AdminAudit::reasonRule(),
        ]);

        $user = $request->user();

        // Lock-out protection: whoever turns it on must already be covered by it. Turning it off is always allowed.
        if ($f['enabled'] && ! $user->hasAdminTwoFactor()) {
            return response()->json([
                'message' => 'Set up your own two-factor authentication before requiring it from everyone.',
                'code' => 'own_two_factor_required',
            ], 422);
        }

        $before = $enforcement->status();
        $enforcement->set($f['enabled'], $user);
        $after = $enforcement->status();

        $audit->record('system.require_two_factor_' . ($f['enabled'] ? 'on' : 'off'), ['system', null], [
            'reason' => $f['reason'],
            'before' => ['enabled' => $before['enabled'], 'enrolled' => $before['enrolled'], 'total' => $before['total']],
            'after' => ['enabled' => $after['enabled'], 'enrolled' => $after['enrolled'], 'total' => $after['total'], 'notEnrolled' => $after['total'] - $after['enrolled']],
        ]);

        return response()->json($after);
    }
}
