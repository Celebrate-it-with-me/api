<?php

use App\Http\Services\AppServices\Hydrate\EventCacheService;
use App\Providers\HydrationServiceProvider;

function bootHydrationProvider(string $env, bool $console): void
{
    $app = app();
    $app->detectEnvironment(fn () => $env);

    $prop = new ReflectionProperty($app, 'isRunningInConsole');
    $prop->setAccessible(true);
    $prop->setValue($app, $console);

    (new HydrationServiceProvider($app))->boot();
}

afterEach(function () {
    app()->detectEnvironment(fn () => 'testing');
    $prop = new ReflectionProperty(app(), 'isRunningInConsole');
    $prop->setAccessible(true);
    $prop->setValue(app(), true);
});

it('does not warm the hydration cache in console, where the tables may not exist yet (migrate on an empty database)', function () {
    $this->mock(EventCacheService::class)->shouldNotReceive('warmUp');

    bootHydrationProvider('production', true);
});

it('warms the hydration cache on web requests in production', function () {
    $this->mock(EventCacheService::class)->shouldReceive('warmUp')->once();

    bootHydrationProvider('production', false);
});

it('does not warm the hydration cache outside production', function () {
    $this->mock(EventCacheService::class)->shouldNotReceive('warmUp');

    bootHydrationProvider('local', false);
});
