<?php

namespace App\Exceptions;

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

        // A bad or expired signed link (reset password, confirm email) is not "access denied": give the SPA a code it can explain.
        $this->renderable(function (InvalidSignatureException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => __('errors.auth.invalid_signature'),
                    'code' => 'invalid_signed_link',
                ], 403);
            }
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
}
