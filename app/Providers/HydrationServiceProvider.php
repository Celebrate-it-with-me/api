<?php

namespace App\Providers;

use App\Http\Services\AppServices\Hydrate\EventAuthorizationService;
use App\Http\Services\AppServices\Hydrate\EventCacheService;
use App\Http\Services\AppServices\Hydrate\EventDataLoader;
use App\Http\Services\AppServices\Hydrate\EventRepository;
use App\Http\Services\AppServices\Hydrate\HydrationResponseBuilder;
use App\Http\Services\AppServices\Hydrate\HydrationService;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\ServiceProvider;

class HydrationServiceProvider extends ServiceProvider
{
    /**
     * Registers hydration services.
     */
    public function register(): void
    {
        $this->app->singleton(EventRepository::class);
        $this->app->singleton(EventAuthorizationService::class);
        $this->app->singleton(EventCacheService::class);
        $this->app->singleton(EventDataLoader::class);

        $this->app->bind(HydrationResponseBuilder::class);
        $this->app->bind(HydrationService::class);
    }

    /**
     * Boots the application services and performs environment-specific initialization.
     *
     * @throws BindingResolutionException
     */
    public function boot(): void
    {
        // Not in console: `migrate` / `migrate:fresh --seed` boot the app before the tables exist, and a warm-up there would
        // also cache empty lists. Web requests (and the first one after a deploy) still warm the cache.
        if ($this->app->environment('production') && ! $this->app->runningInConsole()) {
            $this->app->make(EventCacheService::class)->warmUp();
        }
    }
}
