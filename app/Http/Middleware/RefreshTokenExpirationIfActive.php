<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RefreshTokenExpirationIfActive
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        
        if ($user && !$request->remember_me) {
            $now = now();
            $token = $request->user()->currentAccessToken();
            
            if ($token && $token->expires_at && $token->expires_at->gt($now)) {
                $refreshThreshold = $now->copy()->addMinutes(10);
                
                if ($token->expires_at->lt($refreshThreshold)) {
                    $token->forceFill([
                        'expires_at' => $now->copy()->addHours(5),
                    ])->save();
                }
            }
            
        }
        
        return $next($request);
    }
}
