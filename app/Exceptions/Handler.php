<?php

namespace App\Exceptions;

use App\Http\Services\Payments\PaymentGatewayException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        // Redis down in development: say what to do instead of a stack trace about sockets.
        $this->renderable(function (Throwable $e, Request $request) {
            if (! app()->environment('local', 'staging', 'testing') || ! self::isRedisDown($e)) {
                return null;
            }

            return response()->json([
                'message' => 'Redis is not reachable at ' . config('database.redis.default.host') . ':' . config('database.redis.default.port')
                    . '. Start the cwm_redis container; inside Docker REDIS_HOST must be cwm_redis (not 127.0.0.1), '
                    . 'or switch CACHE_STORE / SESSION_DRIVER / QUEUE_CONNECTION back to file / database / sync.',
                'code' => 'redis_unavailable',
            ], 503);
        });

        // A bad or expired signed link (reset password, confirm email) is not "access denied": give the SPA a code it can explain.
        $this->renderable(function (InvalidSignatureException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => __('errors.auth.invalid_signature'),
                    'code' => 'invalid_signed_link',
                ], 403);
            }
        });

        // The payment provider failed (D8 of the Stripe contract): the only status outside the 403/404/409/422 convention.
        $this->renderable(function (PaymentGatewayException $e, Request $request) {
            return response()->json(['message' => 'The payment provider is not available. Try again in a moment.', 'code' => 'gateway_error'], 502);
        });

        // Every 429 of the API (named limiters, services that throw, abort(429)) has the body of the global `api` limiter.
        $this->renderable(function (HttpExceptionInterface $e, Request $request) {
            if ($e->getStatusCode() === 429 && ($request->expectsJson() || $request->is('api/*'))) {
                return response()->json([
                    'message' => __('errors.too_many_attempts'),
                    'code' => 'too_many_attempts',
                ], 429, $e->getHeaders());
            }
        });
    }

    /** A phpredis or Predis connection failure, however deep in the exception chain. */
    private static function isRedisDown(Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof \RedisException || str_starts_with($current::class, 'Predis\\Connection')) {
                return true;
            }
        }

        return false;
    }
}
