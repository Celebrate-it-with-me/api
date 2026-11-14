<?php

namespace App\Http\Middleware;

use App\Http\Services\Admin\Account\TokenLifetime;
use App\Http\Services\Admin\Account\TwoFactorService;
use App\Http\Services\Admin\AdminAudit;
use App\Http\Services\Admin\Impersonation\ImpersonationService;
use App\Support\AdminPermissions;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/** The admin panel's routes are for the staff roles (superadmin, support). Being logged in is not enough. */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        // A token issued by impersonation is for the app only, whoever it belongs to.
        abort_if(ImpersonationService::isImpersonationToken($request->user()?->currentAccessToken()), 403, 'This area is for administrators.');
        abort_unless($request->user()?->hasAnyRole(AdminPermissions::STAFF_ROLES), 403, 'This area is for administrators.');

        // Idle and absolute expiry of the panel's tokens. The panel's own tokens are revoked; a token of another client
        // (the organizers' app) is only refused here and keeps working where it belongs. Real tokens only: a cookie
        // session (TransientToken) has no timestamps.
        $token = $request->user()->currentAccessToken();
        if ($token instanceof PersonalAccessToken && ($reason = app(TokenLifetime::class)->expiredReason($request, $token))) {
            if ($token->client === 'admin') {
                app(AdminAudit::class)->record('account.session_expired', $request->user(), ['after' => ['reason' => $reason, 'sessionId' => $token->getKey()]]);
                $token->delete();
            }

            return response()->json(['message' => 'Your session expired. Sign in again.', 'code' => 'session_expired', 'reason' => $reason], 401);
        }

        // Two-factor required for superadmins (config admin.require_two_factor_superadmin): until she enrols she can
        // only reach her own account pages and userInfo (to learn she must enrol).
        $user = $request->user();
        if (TwoFactorService::requiredFor($user) && ! $user->hasAdminTwoFactor()
            && ! $request->routeIs('admin.account.*') && $request->route()?->uri() !== 'api/v1/admin/userInfo') {
            return response()->json(['message' => 'Two-factor authentication is required for your role.', 'code' => 'two_factor_required'], 403);
        }

        return $next($request);
    }
}
