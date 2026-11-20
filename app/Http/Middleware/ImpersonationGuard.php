<?php

namespace App\Http\Middleware;

use App\Http\Services\Admin\AdminAudit;
use App\Http\Services\Admin\Impersonation\ImpersonationService;
use App\Models\ImpersonationSession;
use App\Support\AdminPermissions;
use App\Support\ImpersonationRules;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side limits for a token issued by impersonation. Ordinary tokens pass untouched; a panel (`client=admin`) token is refused with 401. With an impersonation
 * token: the session has to be live (else 401), the request is marked with the staff member (`impersonated_by`), and
 * only routes listed in ImpersonationRules::ALLOWED pass; anything else is 403 `impersonation_blocked`.
 */
class ImpersonationGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        // A token issued by the admin panel's own login is for the panel only: the idle/absolute expiry of
        // TokenLifetime is applied by EnsureAdmin, so here it would live forever. The app does not accept it.
        if ($token instanceof PersonalAccessToken && $token->client === 'admin') {
            return response()->json(['message' => __('errors.auth.unauthenticated'), 'code' => 'admin_token_not_accepted'], 401);
        }

        if (! ImpersonationService::isImpersonationToken($token)) {
            return $next($request);
        }

        $session = ImpersonationSession::query()->where('token_id', $token->getKey())->first();

        if (! $session || ! $session->isActive() || $session->user_id !== $request->user()->id) {
            return response()->json(['message' => __('errors.auth.unauthenticated'), 'code' => 'impersonation_ended'], 401);
        }

        // The staff member and the account are checked on every request, not only when the session starts.
        $staff = $session->staff;
        if (! $staff || $staff->suspended_at || ! $staff->hasAnyRole(AdminPermissions::STAFF_ROLES)) {
            app(ImpersonationService::class)->finish($session, 'staff');

            return response()->json(['message' => __('errors.auth.unauthenticated'), 'code' => 'impersonation_ended'], 401);
        }
        if ($request->user()->anonymized_at || $request->user()->hasAnyRole(AdminPermissions::STAFF_ROLES)) {
            app(ImpersonationService::class)->finish($session, 'staff', mail: false);

            return response()->json(['message' => __('errors.auth.unauthenticated'), 'code' => 'impersonation_ended'], 401);
        }

        $request->attributes->set('impersonated_by', $session->staff_id);
        $request->attributes->set('impersonation_session', $session);

        $route = $request->route();

        if (! $route || ! ImpersonationRules::isAllowed($route)) {
            return response()->json([
                'message' => __('errors.permission.impersonation_blocked'),
                'code' => 'impersonation_blocked',
            ], 403);
        }

        $response = $next($request);

        // Whatever the staff member changes while acting as the user leaves a row (no bodies, only what was done).
        if (! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true) && $response->getStatusCode() < 400) {
            $event = $route->parameter('event');
            app(AdminAudit::class)->record('impersonation.action', $request->user(), [
                'actor' => $staff,
                'event_id' => is_object($event) ? $event->getKey() : (is_numeric($event) ? (int) $event : null),
                'after' => ['sessionId' => $session->id, 'method' => $request->method(), 'route' => $route->uri(), 'status' => $response->getStatusCode()],
            ]);
        }

        return $response;
    }
}
