<?php

namespace App\Http\Middleware;

use App\Http\Services\Admin\Impersonation\ImpersonationService;
use App\Support\AdminPermissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The admin panel's routes are for the staff roles (superadmin, support). Being logged in is not enough. */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        // A token issued by impersonation is for the app only, whoever it belongs to.
        abort_if(ImpersonationService::isImpersonationToken($request->user()?->currentAccessToken()), 403, 'This area is for administrators.');
        abort_unless($request->user()?->hasAnyRole(AdminPermissions::STAFF_ROLES), 403, 'This area is for administrators.');

        return $next($request);
    }
}
