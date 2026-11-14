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

        // A suspended member keeps the role but not the access (her tokens were revoked when she was suspended).
        if ($request->user()->admin_suspended_at) {
            return response()->json(['message' => 'Your access to the admin panel is suspended.', 'code' => 'account_suspended'], 403);
        }

        // The panel only answers to the tokens issued by its own login (admin/login, with its two-factor step). A cookie
        // session (TransientToken) comes from the organizers' app login, which asks for the password only, and a token of
        // another client was not issued here: neither may bypass the panel's sign-in.
        $token = $request->user()->currentAccessToken();
        if (! $token instanceof PersonalAccessToken || $token->client !== 'admin') {
            return response()->json(['message' => 'Sign in to the admin panel.', 'code' => 'admin_session_required'], 401);
        }

        // Idle and absolute expiry of the panel's tokens, which are revoked when they expire.
        if ($reason = app(TokenLifetime::class)->expiredReason($request, $token)) {
            app(AdminAudit::class)->record('account.session_expired', $request->user(), ['after' => ['reason' => $reason, 'sessionId' => $token->getKey()]]);
            $token->delete();

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
