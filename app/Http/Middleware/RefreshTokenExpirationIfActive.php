<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Laravel\Sanctum\PersonalAccessToken;

class RefreshTokenExpirationIfActive
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && !$request->remember_me) {
            $now = now();

            $token = $user->currentAccessToken();

            // Only proceed if this is a real Personal Access Token (NOT SPA TransientToken)
            if ($token instanceof PersonalAccessToken) {
                if ($token->expires_at && $token->expires_at->gt($now)) {
                    $refreshThreshold = $now->copy()->addMinutes(10);

                    if ($token->expires_at->lt($refreshThreshold)) {
                        $token->forceFill([
                            'expires_at' => $now->copy()->addHours(5),
                        ])->save();
                    }
                }
            }
        }

        return $next($request);
    }
}
