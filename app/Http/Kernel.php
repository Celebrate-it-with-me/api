<?php

namespace App\Http;

use App\Http\Middleware\Authenticate;
use App\Http\Middleware\EncryptCookies;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureAdminPermission;
use App\Http\Middleware\PreventRequestsDuringMaintenance;
use App\Http\Middleware\RedirectIfAuthenticated;
use App\Http\Middleware\RefreshTokenExpirationIfActive;
use App\Http\Middleware\StatelessForImpersonation;
use App\Http\Middleware\TrimStrings;
use App\Http\Middleware\TrustProxies;
use App\Http\Middleware\ValidateSignature;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Auth\Middleware\AuthenticateWithBasicAuth;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Foundation\Http\Middleware\ValidatePostSize;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Middleware\SetCacheHeaders;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Router;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class Kernel extends HttpKernel
{
    public function __construct(Application $app, Router $router)
    {
        parent::__construct($app, $router);

        // The staff checks run before route-model binding: someone without access gets 403, not a 404 that
        // says whether the record exists.
        $this->addToMiddlewarePriorityBefore(SubstituteBindings::class, [
            EnsureAdmin::class,
            EnsureAdminPermission::class,
        ]);
    }

    protected $middleware = [
        TrustProxies::class,
        HandleCors::class,
        PreventRequestsDuringMaintenance::class,
        ValidatePostSize::class,
        TrimStrings::class,
        ConvertEmptyStringsToNull::class,
    ];

    protected $middlewareGroups = [
        'web' => [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,

            // Optional: enable if you want session authentication hardening
            // AuthenticateSession::class,

            ShareErrorsFromSession::class,
            PreventRequestForgery::class,
            SubstituteBindings::class,
        ],

        'api' => [
            StatelessForImpersonation::class,
            ThrottleRequests::class . ':api',
            SubstituteBindings::class,
        ],
    ];

    protected $middlewareAliases = [
        'auth' => Authenticate::class,
        'auth.basic' => AuthenticateWithBasicAuth::class,
        'auth.session' => AuthenticateSession::class,
        'cache.headers' => SetCacheHeaders::class,
        'can' => Authorize::class,
        'guest' => RedirectIfAuthenticated::class,
        'password.confirm' => RequirePassword::class,
        'precognitive' => HandlePrecognitiveRequests::class,
        'signed' => ValidateSignature::class,
        'throttle' => ThrottleRequests::class,
        'verified' => EnsureEmailIsVerified::class,
        'not.suspended' => \App\Http\Middleware\EnsureNotSuspended::class,
        'refresh.token' => RefreshTokenExpirationIfActive::class,
        'impersonation.guard' => \App\Http\Middleware\ImpersonationGuard::class,
        'admin' => \App\Http\Middleware\EnsureAdmin::class,
        'admin.can' => \App\Http\Middleware\EnsureAdminPermission::class,
        'event.access' => \App\Http\Middleware\EnsureEventAccess::class,
        'event.guest' => \App\Http\Middleware\EnsureGuestEventAccess::class,
        'module.access' => \App\Http\Middleware\EnsureModuleAccess::class,
    ];
}
