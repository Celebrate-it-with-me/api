<?php

namespace App\Http\Middleware;

use App\Support\AdminPermissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** `admin.can:admin.refunds`: staff only, and the role has to carry that permission. */
class EnsureAdminPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        abort_unless($user?->hasAnyRole(AdminPermissions::STAFF_ROLES) && $user->hasPermissionTo($permission, 'web'), 403, 'Your role cannot do this.');

        return $next($request);
    }
}
