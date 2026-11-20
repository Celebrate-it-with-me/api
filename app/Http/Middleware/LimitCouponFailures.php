<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Brake on guessing coupon codes at `purchase/quote` and `purchase/checkout` (a free coupon unlocks the plan without
 * payment). Only INVALID codes count (a 422 with `invalid_code`), so paying customers are never slowed down.
 * 10 failures in 10 minutes per user and, separately, per IP; both routes share the same counters. Past that,
 * every request gets the same 429 until the window passes.
 */
class LimitCouponFailures
{
    public const MAX_FAILURES = 10;

    public const WINDOW_SECONDS = 600;

    public function handle(Request $request, Closure $next): Response
    {
        $keys = array_filter([
            'coupon-failures-user|' . $request->user()?->id => $request->user() !== null,
            'coupon-failures-ip|' . $request->ip() => true,
        ]);

        foreach (array_keys($keys) as $key) {
            if (RateLimiter::tooManyAttempts($key, self::MAX_FAILURES)) {
                return response()->json([
                    'message' => __('errors.too_many_attempts'),
                    'code' => 'too_many_attempts',
                ], 429, ['Retry-After' => RateLimiter::availableIn($key)]);
            }
        }

        $response = $next($request);

        if ($response->getStatusCode() === 422 && $this->isCodeError($response)) {
            foreach (array_keys($keys) as $key) {
                RateLimiter::hit($key, self::WINDOW_SECONDS);
            }
        }

        return $response;
    }

    private function isCodeError(Response $response): bool
    {
        $body = json_decode((string) $response->getContent(), true);

        return is_array($body) && in_array('invalid_code', (array) ($body['errors']['code'] ?? []), true);
    }
}
