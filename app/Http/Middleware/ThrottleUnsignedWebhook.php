<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * Throttle for webhooks that never starves the provider. Behind a balancer every caller can share one IP
 * (TRUSTED_PROXIES empty), so a tight per-IP limit placed before the signature check would let garbage traffic lock the
 * provider out. Two tiers:
 *
 *  - A request with NO signature header (pure noise) goes through the strict named limiter.
 *  - A request WITH a signature header skips that one, but is not free: it passes a generous per-IP limiter, a body
 *    size cap and a shape check of the header, all BEFORE the gateway spends an HMAC on it. Only a header shaped like
 *    a real signature reaches the gateway, which still verifies it over the raw body.
 *
 * Usage: `throttle.unsigned:{unsignedLimiter},{signedLimiter},{maxBodyKb}`.
 */
class ThrottleUnsignedWebhook
{
    /** Header => the shape a genuine signature has. Checked with a regex, never verified here. */
    private const SIGNATURE_HEADERS = [
        'Stripe-Signature' => '/^t=\d+(,v\d+=[0-9a-f]+)*,v1=[0-9a-f]{64}(,v\d+=[0-9a-f]+)*$/i',
        'X-Signature' => '/^[0-9a-f]{64}$/i',
        'X-Hub-Signature-256' => '/^sha256=[0-9a-f]{64}$/i',
    ];

    public function __construct(private readonly ThrottleRequests $throttle) {}

    public function handle(Request $request, Closure $next, string $unsignedLimiter, ?string $signedLimiter = null, int $maxBodyKb = 256): Response
    {
        $present = array_values(array_filter(array_keys(self::SIGNATURE_HEADERS), fn ($h) => $request->headers->has($h)));
        if ($present === []) {
            return $this->throttle->handle($request, $next, $unsignedLimiter);
        }

        $guard = function (Request $request) use ($present, $next, $maxBodyKb) {
            if ((int) $request->header('Content-Length') > $maxBodyKb * 1024 || strlen($request->getContent()) > $maxBodyKb * 1024) {
                return response()->json(['message' => 'Payload too large.'], 413);
            }
            foreach ($present as $header) {
                if (! preg_match(self::SIGNATURE_HEADERS[$header], (string) $request->header($header))) {
                    // Meta's endpoint has always answered a bad signature with an empty 403; Stripe's and ours with 400.
                    return $header === 'X-Hub-Signature-256' ? response('', 403) : response()->json(['message' => 'Invalid signature.'], 400);
                }
            }

            return $next($request);
        };

        return $signedLimiter
            ? $this->throttle->handle($request, $guard, $signedLimiter)
            : $guard($request);
    }
}
