<?php

namespace App\Http\Middleware;

use App\Http\Services\Admin\Impersonation\ImpersonationService;
use App\Models\ImpersonationSession;
use App\Support\ImpersonationRules;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side limits for a token issued by impersonation. Ordinary tokens pass untouched. With an impersonation
 * token: the session has to be live (else 401), the request is marked with the staff member (`impersonated_by`), and
 * only routes listed in ImpersonationRules::ALLOWED pass; anything else is 403 `impersonation_blocked`.
 */
class ImpersonationGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if (! ImpersonationService::isImpersonationToken($token)) {
            return $next($request);
        }

        $session = ImpersonationSession::query()->where('token_id', $token->getKey())->first();

        if (! $session || ! $session->isActive() || $session->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthenticated.', 'code' => 'impersonation_ended'], 401);
        }

        $request->attributes->set('impersonated_by', $session->staff_id);
        $request->attributes->set('impersonation_session', $session);

        $route = $request->route();

        if (! $route || ! ImpersonationRules::isAllowed($route)) {
            return response()->json([
                'message' => 'Esta acción no está permitida durante una sesión de soporte.',
                'code' => 'impersonation_blocked',
            ], 403);
        }

        return $next($request);
    }
}
