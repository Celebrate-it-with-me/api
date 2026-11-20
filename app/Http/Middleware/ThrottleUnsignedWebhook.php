<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * Throttle for the payment webhook that never starves the provider. Behind a balancer every caller can share one IP
 * (TRUSTED_PROXIES empty), so a per-IP limit placed before the signature check would let garbage traffic lock Stripe
 * out. Requests that carry a signature header skip the throttle: the gateway verifies the signature over the raw
 * body and answers 400 to a forged one at the cost of one HMAC. Requests WITHOUT any signature header (pure noise)
 * still go through the named limiter.
 */
class ThrottleUnsignedWebhook
{
    private const SIGNATURE_HEADERS = ['Stripe-Signature', 'X-Signature'];

    public function __construct(private readonly ThrottleRequests $throttle) {}

    public function handle(Request $request, Closure $next, string $limiter): Response
    {
        foreach (self::SIGNATURE_HEADERS as $header) {
            if ($request->headers->has($header)) {
                return $next($request);
            }
        }

        return $this->throttle->handle($request, $next, $limiter);
    }
}
