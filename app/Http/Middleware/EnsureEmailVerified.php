<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Publishing and buying need a verified email. Nothing else does. A support session (impersonation) passes: staff
 * acts for the owner and cannot verify her address.
 */
class EnsureEmailVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->hasVerifiedEmail() && ! $request->attributes->get('impersonated_by')) {
            return response()->json([
                'message' => __('errors.auth.email_not_verified'),
                'code' => 'email_not_verified',
            ], 403);
        }

        return $next($request);
    }
}
