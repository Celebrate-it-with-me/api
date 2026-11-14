<?php

namespace App\Http\Middleware;

use App\Http\Services\Admin\Account\ReauthService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `admin.reauth`: the very sensitive admin actions need a re-authentication newer than `admin.reauth_minutes`
 * (`POST admin/account/reauth`). Without one: 403 `{code: 'reauth_required'}`. Put it AFTER `admin.can`, so someone
 * who may not do the action learns nothing else about it. A request without a real token (cookie session) never
 * passes: it has nowhere to remember the re-authentication.
 */
class EnsureRecentReauth
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! ReauthService::isFresh($request->user()?->currentAccessToken())) {
            return response()->json([
                'message' => 'Confirm your password to continue.',
                'code' => 'reauth_required',
                'reauthMinutes' => (int) config('admin.reauth_minutes'),
            ], 403);
        }

        return $next($request);
    }
}
