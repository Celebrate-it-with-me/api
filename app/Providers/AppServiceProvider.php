<?php

namespace App\Providers;

use App\Models\Guest;
use App\Models\User;
use App\Http\Services\Admin\Account\TokenLifetime;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Laravel\Sanctum\Events\TokenAuthenticated;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ImageManager::class, function($app) {
            return new ImageManager(new Driver());
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // we need to migrate old data to use guest and user
        Relation::enforceMorphMap([
            'guest' => Guest::class,
            'user' => User::class,
        ]);

        // Sanctum overwrites `last_used_at` while authenticating; keep the previous value for the idle check of the admin panel.
        Event::listen(TokenAuthenticated::class, fn (TokenAuthenticated $e) => TokenLifetime::remember(request(), $e->token));
    }
}
