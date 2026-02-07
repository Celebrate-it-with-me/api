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
     *
     * @return void
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
     * @return void
     * @throws BindingResolutionException
     */
    public function boot(): void
    {
        if ($this->app->environment('production')) {
            $this->app->make(EventCacheService::class)->warmUp();
        }
    }
}
