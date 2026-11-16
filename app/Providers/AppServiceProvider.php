<?php

namespace App\Providers;

use App\Http\Services\Admin\Account\TokenLifetime;
use App\Models\Guest;
use App\Models\User;
use App\Http\Services\AppServices\EventModules;
use App\Support\Mail\MessageOverrides;
use App\Support\Mail\OverridableTranslator;
use App\Support\ProductionSafetyCheck;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Translation\Translator;
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
        $this->app->singleton(ImageManager::class, function ($app) {
            return new ImageManager(new Driver);
        });

        // Lines of the `mail` lang group can be overridden by the admin (message editor); everything else is untouched.
        $this->app->singleton(MessageOverrides::class);

        // One module resolver per request (and per queue job under Octane): the resources, the setup progress and the
        // hydration all ask it, and a new instance each time repeated its three queries for every event.
        $this->app->scoped(EventModules::class);
        $this->app->extend('translator', function (Translator $translator) {
            $custom = new OverridableTranslator($translator->getLoader(), $translator->getLocale());
            $custom->setFallback($translator->getFallback());

            return $custom;
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

        // A production server must not run with a development configuration (see ProductionSafetyCheck).
        if ($this->app->environment('production') && ! $this->app->runningInConsole() && ($problems = ProductionSafetyCheck::problems())) {
            throw new \RuntimeException('Unsafe production configuration: ' . implode(' ', $problems));
        }

        // Sanctum overwrites `last_used_at` while authenticating; keep the previous value for the idle check of the admin panel.
        Event::listen(TokenAuthenticated::class, fn (TokenAuthenticated $e) => TokenLifetime::remember(request(), $e->token));
    }
}
