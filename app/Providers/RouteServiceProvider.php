<?php

namespace App\Providers;

use App\Http\Controllers\HealthController;
use App\Http\Controllers\InvitationShareController;
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
        // Legacy `/status` (opens a DB connection). The balancer should use `/up`; this one is capped and cached for a few seconds.
        'status' => [120, 1],
        'og-share' => [60, 1],
        'template-assets' => [600, 1],
        // Creating events: a normal organizer makes a handful; 20 an hour per account is far above that and stops mass creation.
        'event-create' => [20, 60],
        'invitation-show' => [60, 1],
        'invitation-access' => [60, 1],
        'invitation-rsvp' => [20, 1],
        'invitation-calendar' => [30, 1],
        'invitation-comments-read' => [60, 1],
        'invitation-comments-write' => [10, 1],
        'invitation-songs-read' => [60, 1],
        'invitation-songs-search' => [20, 1],
        'invitation-songs-write' => [10, 1],
        'invitation-songs-vote' => [30, 1],
        'invitation-edit' => [60, 1],
        'invitation-preview' => [30, 1],
        'invitation-cover-upload' => [10, 1],
        'background-music-upload' => [10, 1],
        'guest-event' => [60, 1],
        'payments-webhook' => [120, 1],
        // Signed-looking webhooks (header present): generous per IP, because a balancer can make every caller one IP.
        'payments-webhook-signed' => [600, 1],
        'reset-password' => [5, 1],
        'oauth' => [20, 1],
        'collaborator-token' => [30, 1],
        'collaborator-resend' => [10, 1],
        'support-request' => [5, 1],
        'spotify-search' => [30, 1],
        'guest-export' => [10, 1],
        'whatsapp-send' => [20, 1],
        'whatsapp-retry' => [30, 1],
        'whatsapp-preview' => [120, 1],
        'whatsapp-webhook-verify' => [60, 1],
        'whatsapp-webhook-receive' => [1200, 1],
        'whatsapp-webhook-unsigned' => [60, 1],
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
            $tooMany = fn (Request $request, array $headers) => response()->json([
                'message' => __('errors.too_many_attempts'),
                'code' => 'too_many_attempts',
            ], 429, $headers);

            // A signed-in user's reads (GET/HEAD) have their own, larger counter: one visit to the Inicio is ~14 reads,
            // so 120 a minute was spent by 4-8 reloads or event switches (B3). Writes keep the 120 counter they had
            // (same key), anonymous traffic stays per IP at 120, and every route-specific limit still applies on top.
            if ($request->user() !== null && $request->isMethodSafe()) {
                return Limit::perMinute((int) config('app.api_read_rate_limit_per_minute', 300))
                    ->by('api-read|user:' . $request->user()->id)
                    ->response($tooMany);
            }

            return Limit::perMinute((int) config('app.api_rate_limit_per_minute', 120))
                ->by($request->user()?->id ?: $request->ip())
                ->response($tooMany);
        });

        // Unnamed `throttle:N,M` shares one counter per IP/user across ALL such routes (the signature has no route),
        // so every limit gets its own name: [attempts, minutes].
        foreach (self::ROUTE_LIMITS as $name => [$attempts, $minutes]) {
            RateLimiter::for($name, fn (Request $request) => Limit::perMinutes($minutes, $attempts)
                ->by($name . '|' . ($request->user()?->id ? 'user:' . $request->user()->id : 'ip:' . $request->ip())));
        }

        // "Reenviar correo" of the email verification: 3 per 10 minutes per user and per IP.
        RateLimiter::for('email-resend', function (Request $request) {
            $tooMany = fn (Request $request, array $headers) => response()->json([
                'message' => __('errors.too_many_attempts'),
                'code' => 'too_many_attempts',
            ], 429, $headers);

            return [
                Limit::perMinutes(10, 3)->by('email-resend-user|' . $request->user()?->id)->response($tooMany),
                Limit::perMinutes(10, 3)->by('email-resend-ip|' . $request->ip())->response($tooMany),
            ];
        });
        // Place photos: each miss is a Google Places call plus downloads. Per user and per event (a day cap protects the quota).
        RateLimiter::for('location-place-images', function (Request $request) {
            $event = $request->route('event');

            return [
                Limit::perMinute(10)->by('place-images-user|' . ($request->user()?->id ?: $request->ip())),
                Limit::perDay(100)->by('place-images-event|' . (is_object($event) ? $event->getKey() : $event)),
            ];
        });

        RateLimiter::for('email-verify', fn (Request $request) => Limit::perMinute(20)->by('email-verify|' . $request->ip()));

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

        // The code gate (docs/plans/invitation-code-gate-contract.md): trying codes is what an attacker does, so the POST
        // is limited per IP (5 per minute, 30 per hour) and per slug (10 per minute, whoever asks). Same 429 body as the rest.
        RateLimiter::for('invitation-access-verify', function (Request $request) {
            $tooMany = fn (Request $request, array $headers) => response()->json([
                'message' => __('errors.too_many_attempts'),
                'code' => 'too_many_attempts',
            ], 429, $headers);

            return [
                Limit::perMinute(5)->by('invitation-access-ip-min|' . $request->ip())->response($tooMany),
                Limit::perHour(30)->by('invitation-access-ip-hour|' . $request->ip())->response($tooMany),
                Limit::perMinute(10)->by('invitation-access-slug|' . mb_strtolower((string) $request->route('slug')))->response($tooMany),
            ];
        });

        // Comments written from one invitation: 6 per 10 minutes by link (slug + code), besides the per-IP limit.
        RateLimiter::for('invitation-comments-code', fn (Request $request) => Limit::perMinutes(10, 6)
            ->by('invitation-comments-code|' . $request->route('slug') . '|' . $request->route('code'))
            ->response(fn (Request $request, array $headers) => response()->json([
                'message' => __('errors.too_many_attempts'),
                'code' => 'too_many_attempts',
            ], 429, $headers)));

        // Songs of one invitation (search, suggest/remove, vote): besides the per-IP limits, a budget per link (slug + code)
        // in 10 minutes, so one family cannot be hammered from many addresses nor spend our Spotify quota.
        foreach (['search' => 20, 'write' => 10, 'vote' => 30] as $kind => $attempts) {
            RateLimiter::for("invitation-songs-{$kind}-code", fn (Request $request) => Limit::perMinutes(10, $attempts)
                ->by("invitation-songs-{$kind}-code|" . $request->route('slug') . '|' . $request->route('code'))
                ->response(fn (Request $request, array $headers) => response()->json([
                    'message' => __('errors.too_many_attempts'),
                    'code' => 'too_many_attempts',
                ], 429, $headers)));
        }

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

            // Link preview of an invitation (WhatsApp, Facebook, iMessage…). The app's host sends crawlers here. Outside the
            // web group on purpose: no session and no Set-Cookie for a crawler (the Blade needs neither). Wrong codes are
            // braked per event by `code.failures`; the per-IP limit is generous because crawlers share addresses.
            Route::middleware(['code.failures', 'throttle:og-share'])
                ->get('og/{slug}/{code}', [InvitationShareController::class, 'show'])
                ->name('invitation.share');

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
