<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Common brake for every public route that confirms (200) or denies (404/422) a guest code: the invitation, its
 * calendar file, the link-preview page and the guest-facing template routes.
 *
 * Only FAILURES count, so guests who hold a valid code never use up the budget. Two counters per minute: one per
 * event (slug or id, whoever asks, so rotating IPs does not help) and a tighter one per IP + event. Once either
 * is full every request, valid or not, gets the same 429 until the minute passes; the code space (~11.7 million)
 * cannot be walked at that speed. The per-route IP throttles stay as a second layer.
 */
class LimitCodeFailures
{
    public const PER_EVENT = 20;

    public const PER_IP_AND_EVENT = 10;

    public function handle(Request $request, Closure $next): Response
    {
        $event = $this->eventKey($request);
        $keys = [
            self::PER_EVENT => 'code-failures-event|' . $event,
            self::PER_IP_AND_EVENT => 'code-failures-ip|' . $request->ip() . '|' . $event,
        ];

        foreach ($keys as $max => $key) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                return response()->json([
                    'message' => __('errors.too_many_attempts'),
                    'code' => 'too_many_attempts',
                ], 429, ['Retry-After' => RateLimiter::availableIn($key)]);
            }
        }

        $response = $next($request);

        if (in_array($response->getStatusCode(), [404, 422], true)) {
            foreach ($keys as $key) {
                RateLimiter::hit($key, 60);
            }
        }

        return $response;
    }

    private function eventKey(Request $request): string
    {
        $event = $request->route('slug') ?? $request->route('event');

        return mb_strtolower((string) (is_object($event) && isset($event->id) ? $event->id : $event));
    }
}
