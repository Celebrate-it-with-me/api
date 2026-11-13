<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A suspended account cannot use the app, even with a token that was issued before the suspension. */
class EnsureNotSuspended
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->suspended_at) {
            return self::response();
        }

        return $next($request);
    }

    /** The contract the app reads to show the "your access was suspended" screen. */
    public static function response(): JsonResponse
    {
        return response()->json([
            'message' => 'Your access was suspended. Write to support.',
            'code' => 'account_suspended',
            'supportEmail' => config('services.support.email'),
        ], 403);
    }
}
