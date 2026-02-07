<?php

namespace App\Providers;

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

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        $this->routes(function () {
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
        });
    }

    protected function configureRateLimiting(): void
    {
        RateLimiter::for('public-event-comments-ip', function (Request $request) {
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
        });
    }
}
