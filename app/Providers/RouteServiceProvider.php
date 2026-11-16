<?php

namespace App\Providers;

use App\Http\Controllers\HealthController;
use App\Http\Controllers\TemplateAssetController;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /** Named rate limiters, one per route (or deliberate group of routes): name => [attempts, window in minutes]. */
    public const ROUTE_LIMITS = [
        'system-status' => [60, 1],
        'og-share' => [60, 1],
        'template-assets' => [600, 1],
        'invitation-show' => [60, 1],
        'invitation-rsvp' => [20, 1],
        'invitation-calendar' => [30, 1],
        'invitation-edit' => [60, 1],
        'invitation-preview' => [30, 1],
        'invitation-cover-upload' => [10, 1],
        'background-music-upload' => [10, 1],
        'guest-event' => [60, 1],
        'payments-webhook' => [120, 1],
        'reset-password' => [5, 1],
        'collaborator-token' => [30, 1],
        'collaborator-resend' => [10, 1],
        'support-request' => [5, 1],
        'spotify-search' => [30, 1],
        'guest-export' => [10, 1],
        'whatsapp-send' => [20, 1],
        'whatsapp-retry' => [30, 1],
        'whatsapp-webhook-verify' => [60, 1],
        'whatsapp-webhook-receive' => [1200, 1],
        'invoice-resend' => [5, 10],
        'admin-login' => [10, 1],
        'admin-login-2fa' => [10, 1],
        'admin-invitation' => [10, 1],
        'admin-2fa-reset' => [10, 1],
        'admin-invitation-resend' => [6, 1],
        'admin-whatsapp-test' => [10, 1],
        'assistant-read' => [120, 1],
        'assistant-write' => [60, 1],
        'admin-template-package-upload' => [6, 1],
        'admin-template-package-install' => [10, 1],
        'admin-template-package-change' => [10, 1],
    ];

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute((int) config('app.api_rate_limit_per_minute', 120))
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn (Request $request, array $headers) => response()->json([
                    'message' => __('errors.too_many_attempts'),
                    'code' => 'too_many_attempts',
                ], 429, $headers));
        });

        // Unnamed `throttle:N,M` shares one counter per IP/user across ALL such routes (the signature has no route),
        // so every limit gets its own name: [attempts, minutes].
        foreach (self::ROUTE_LIMITS as $name => [$attempts, $minutes]) {
            RateLimiter::for($name, fn (Request $request) => Limit::perMinutes($minutes, $attempts)
                ->by($name . '|' . ($request->user()?->id ? 'user:' . $request->user()->id : 'ip:' . $request->ip())));
        }

        // Inviting collaborators sends mail from our domain and tells whether an account exists (audit W-03).
        RateLimiter::for('collaborator-invites', function (Request $request) {
            $event = $request->route('event');

            return [
                Limit::perMinute(10)->by('collab-user:' . ($request->user()?->id ?: $request->ip())),
                Limit::perDay(30)->by('collab-event:' . (is_object($event) ? $event->getKey() : $event)),
            ];
        });

        // Answers to one invitation are limited by the link (slug + code), not only by IP: one family cannot be
        // hammered from many addresses. 10 per 10 minutes.
        RateLimiter::for('invitation-rsvp-code', fn (Request $request) => Limit::perMinutes(10, 10)
            ->by('invitation-rsvp-code|' . $request->route('slug') . '|' . $request->route('code'))
            ->response(fn (Request $request, array $headers) => response()->json([
                'message' => __('errors.too_many_attempts'),
                'code' => 'too_many_attempts',
            ], 429, $headers)));

        $this->routes(function () {
            // Health check for the balancer: outside every group on purpose (no throttle, session or maintenance).
            Route::get('up', HealthController::class)->name('health');

            Route::middleware('api')
                ->prefix('api/v1/')
                ->group(base_path('routes/api.php'));

            Route::middleware('api')
                ->prefix('api/v1/app')
                ->group(base_path('routes/api/app.php'));

            Route::middleware('api')
                ->prefix('api/v1/admin')
                ->group(base_path('routes/api/admin.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));

            // Sanitized package files, outside the web and api groups on purpose: no session, no cookies (W6-08).
            Route::middleware('throttle:template-assets')
                ->get('template-assets/{path}', [TemplateAssetController::class, 'show'])
                ->where('path', '.*');
        });
    }

    protected function configureRateLimiting(): void
    {
        /*RateLimiter::for('public-event-comments-ip', function (Request $request) {
            $eventId = (string) $request->route('event')?->id ?? (string) $request->route('event');
            $ip = (string) $request->ip();

            // Example: 20 comments per 10 minutes per IP per event
            return Limit::perMinutes(10, 20)->by("event:{$eventId}|ip:{$ip}");
        });

        RateLimiter::for('public-event-comments-guest', function (Request $request) {
            $eventId = (string) $request->route('event')?->id ?? (string) $request->route('event');
            $guestCode = (string) $request->input('guestCode', 'unknown');

            // Example: 6 comments per 10 minutes per guestCode per event
            return Limit::perMinutes(10, 6)->by("event:{$eventId}|guest:{$guestCode}");
        });*/
    }
}
