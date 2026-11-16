<?php

use App\Http\Services\AppServices\EventModules;
use App\Models\EventModuleOverride;
use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\Module;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

function cachedEvent(string $plan = 'basic'): Events
{
    return Events::factory()->draft()->create([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', $plan)->value('id'),
    ]);
}

/** What a fresh request sees: a new resolver, as the container hands one out per request. */
function modulesNow(): EventModules
{
    app()->forgetScopedInstances();
    request()->attributes->set('fresh', uniqid());
    app()->instance('request', Request::create('/'));

    return app(EventModules::class);
}

/** @return list<string> the module reads (catalog, plan, exceptions) done by $work */
function moduleReads(Closure $work): array
{
    $seen = [];
    DB::listen(function ($q) use (&$seen) {
        if (preg_match('/from "(modules|module_plan|event_module_overrides)"/', $q->sql)) {
            $seen[] = $q->sql;
        }
    });
    $work();

    return $seen;
}

foreach (['array', 'file'] as $driver) {
    describe("with the {$driver} cache store", function () use ($driver) {
        beforeEach(function () use ($driver) {
            config(['cache.default' => $driver]);
            Cache::flush();
        });

        it('reads the catalog, the plan and the exceptions once, then answers from the cache', function () {
            $event = cachedEvent();

            $first = moduleReads(fn () => modulesNow()->forEvent($event));
            $second = moduleReads(fn () => modulesNow()->forEvent($event));

            expect($first)->not->toBeEmpty()->and($second)->toBeEmpty();
        });

        it('returns the same answer from the cache as from the database', function () {
            $event = cachedEvent('premium');
            EventModuleOverride::create(['event_id' => $event->id, 'module_key' => 'budget', 'state' => 'locked', 'reason' => 'test']);

            $fromDb = modulesNow()->forEvent($event);
            $fromCache = modulesNow()->forEvent($event);

            expect($fromCache)->toBe($fromDb)->and(collect($fromCache)->firstWhere('key', 'budget')['state'])->toBe('locked');
        });

        it('sees an upgrade at once: the plan is part of the event, not of the cache', function () {
            $event = cachedEvent('basic');
            $before = collect(modulesNow()->forEvent($event))->pluck('state', 'key');

            $event->update(['event_plan_id' => EventPlan::where('slug', 'premium')->value('id')]);
            $after = collect(modulesNow()->forEvent($event->fresh()))->pluck('state', 'key');

            $locked = Module::enabled()->get()->filter(fn ($m) => $before[$m->key] === 'locked')->pluck('key');
            expect($locked)->not->toBeEmpty()->and($locked->every(fn ($k) => $after[$k] === 'active'))->toBeTrue();
        });

        it('sees a module switched off or on by the admin', function () {
            $event = cachedEvent('premium');
            expect(modulesNow()->stateOf($event, 'budget'))->toBe('active');

            Module::where('key', 'budget')->first()->update(['is_enabled' => false]);
            expect(modulesNow()->stateOf($event, 'budget'))->toBeNull();

            Module::where('key', 'budget')->first()->update(['is_enabled' => true]);
            expect(modulesNow()->stateOf($event, 'budget'))->toBe('active');
        });

        it('sees a plan gaining or losing a module through the pivot', function () {
            $event = cachedEvent('basic');
            $module = Module::where('key', 'budget')->first();

            $module->plans()->sync([$event->event_plan_id]);
            app(EventModules::class)->invalidate();
            expect(modulesNow()->stateOf($event, 'budget'))->toBe('active');

            $module->plans()->sync([]);
            app(EventModules::class)->invalidate();
            expect(modulesNow()->stateOf($event, 'budget'))->toBe('locked');
        });

        it('sees a staff exception set, changed and removed', function () {
            $event = cachedEvent('basic');
            $base = modulesNow()->stateOf($event, 'budget');
            $other = $base === 'active' ? 'locked' : 'active';

            $override = EventModuleOverride::create(['event_id' => $event->id, 'module_key' => 'budget', 'state' => $other, 'reason' => 'test']);
            expect(modulesNow()->stateOf($event, 'budget'))->toBe($other);

            $override->update(['state' => $base]);
            expect(modulesNow()->stateOf($event, 'budget'))->toBe($base);

            $override->update(['state' => $other]);
            expect(modulesNow()->stateOf($event, 'budget'))->toBe($other);

            $override->delete();
            expect(modulesNow()->stateOf($event, 'budget'))->toBe($base);
        });

        it('stops honouring an exception once it expires, without a write', function () {
            $event = cachedEvent('basic');
            $base = modulesNow()->stateOf($event, 'budget');
            $other = $base === 'active' ? 'locked' : 'active';
            EventModuleOverride::create(['event_id' => $event->id, 'module_key' => 'budget', 'state' => $other, 'expires_at' => now()->addMinute(), 'reason' => 'test']);

            expect(modulesNow()->stateOf($event, 'budget'))->toBe($other);

            $this->travel(2)->minutes();
            expect(modulesNow()->stateOf($event, 'budget'))->toBe($base);
        });

        it('keeps the exceptions of one event apart from another', function () {
            $a = cachedEvent('basic');
            $b = cachedEvent('basic');
            $base = modulesNow()->stateOf($a, 'budget');
            $other = $base === 'active' ? 'locked' : 'active';
            EventModuleOverride::create(['event_id' => $a->id, 'module_key' => 'budget', 'state' => $other, 'reason' => 'test']);

            expect(modulesNow()->stateOf($a, 'budget'))->toBe($other)->and(modulesNow()->stateOf($b, 'budget'))->toBe($base);
        });
    });
}

it('answers from the database when the cache store fails', function () {
    Cache::extend('broken', fn () => throw new RuntimeException('store down'));
    config(['cache.stores.broken' => ['driver' => 'broken'], 'cache.default' => 'broken']);
    $event = cachedEvent('premium');

    expect(modulesNow()->stateOf($event, 'budget'))->toBe('active');
});
