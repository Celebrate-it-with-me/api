<?php

namespace App\Http\Services\AppServices;

use App\Models\EventModuleOverride;
use App\Models\Events;
use App\Models\Module;
use Closure;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Which modules an event shows, and in what state, from the catalog in the database:
 *
 *   active  the event's plan includes the module
 *   locked  the module is enabled but the plan does not include it (shown with an upgrade prompt)
 *   (absent) the admin disabled the module: nobody sees it
 *
 * A staff exception for the event (`event_module_overrides`, not expired) wins over the plan: gifted modules are
 * active, hand-locked ones are locked. The exceptions are read per event (one query each), or for a whole list at once with preload().
 *
 * Within a request the answer is kept in memory, so a list of events costs no more than one event. Across requests the
 * three reads (the catalog, which modules each plan includes, and the exceptions of each event) are kept in the
 * application cache for a short time (TTL), through the plain `Cache` interface: any store works, and if the store fails
 * the database answers. Every change goes through invalidate(), which moves a version number that is part of every cache
 * key, so nothing stale is read once a write has happened (the TTL only bounds a change made behind the app's back,
 * for instance by a migration).
 */
class EventModules
{
    public const ACTIVE = 'active';

    public const LOCKED = 'locked';

    /** Seconds a cached read is trusted without a write telling otherwise. */
    public const CACHE_TTL = 300;

    /** The version token every other key carries; replacing it drops them all at once, on any store. */
    public const VERSION_KEY = 'modules.version';

    private ?int $requestId = null;

    private ?string $version = null;

    /** After a write in this request the cache is left alone (read and write) until the request ends. */
    private bool $bypass = false;

    private ?array $bundle = null;

    /** @var list<array{key: string, section: string, sort_order: int}>|null */
    private ?array $catalog = null;

    private bool $warned = false;

    /** @var array<int, list<string>> */
    private array $includedByPlan = [];

    /** @var array<int, array<string, string>> event id => module key => state, for the events read so far */
    private array $overrides = [];

    /** @return list<array{key: string, section: string, order: int, state: string}> */
    public function forEvent(Events $event): array
    {
        return array_map(
            fn (array $m) => ['key' => $m['key'], 'section' => $m['section'], 'order' => $m['order'], 'state' => $m['state']],
            $this->detailedForEvent($event),
        );
    }

    /**
     * Like forEvent, plus what the plan alone says (`base`) and whether a staff exception decides the state.
     *
     * @return list<array{key: string, section: string, order: int, state: string, base: string, overridden: bool}>
     */
    public function detailedForEvent(Events $event): array
    {
        $included = $this->includedKeys($event->event_plan_id);
        $overrides = $this->overridesFor($event->id);

        return array_map(function (array $module) use ($included, $overrides) {
            $base = in_array($module['key'], $included, true) ? self::ACTIVE : self::LOCKED;

            return [
                'key' => $module['key'],
                'section' => $module['section'],
                'order' => $module['sort_order'],
                'state' => $overrides[$module['key']] ?? $base,
                'base' => $base,
                'overridden' => isset($overrides[$module['key']]),
            ];
        }, $this->catalog());
    }

    /** Drop what was read in this request (to read the exceptions again, after one changed). */
    public function forget(): void
    {
        $this->overrides = [];
    }

    /**
     * The catalog, a plan's modules or a staff exception changed: forget what this request read and make every cached
     * read of every request obsolete. Called by the models and services that write them; call it after any bulk write
     * that skips the models (insert, update or delete through the query builder, or the pivot of `module_plan`).
     */
    public function invalidate(): void
    {
        $this->forget();
        $this->catalog = null;
        $this->includedByPlan = [];
        $this->version = null;
        $this->bundle = null;
        $this->bypass = true;

        // After the commit when there is a transaction: a request that reads in between must not cache the old rows
        // under the new version.
        DB::afterCommit(fn () => $this->guard(fn () => Cache::forever(self::VERSION_KEY, (string) Str::uuid())));
    }

    /** `active`, `locked`, or null when the module does not exist or the admin turned it off. */
    public function stateOf(Events $event, string $key): ?string
    {
        foreach ($this->forEvent($event) as $module) {
            if ($module['key'] === $key) {
                return $module['state'];
            }
        }

        return null;
    }

    public function isActive(Events $event, string $key): bool
    {
        return $this->stateOf($event, $key) === self::ACTIVE;
    }

    /**
     * Read the exceptions of several events in one query (a list of events), so asking about each costs nothing more.
     *
     * @param  iterable<int, Events|int>|AbstractPaginator  $events
     */
    public function preload(iterable $events): void
    {
        $this->resetIfNewRequest();

        if ($events instanceof AbstractPaginator) {
            $events = $events->items();
        }

        $ids = collect($events)->map(fn ($e) => $e instanceof Events ? $e->id : (int) $e)
            ->reject(fn ($id) => array_key_exists($id, $this->overrides))->unique()->values();

        if ($ids->isEmpty()) {
            return;
        }

        $this->readOverrides($ids->all());
    }

    /** @return array<string, string> module key => state, for the event's current exceptions */
    private function overridesFor(int $eventId): array
    {
        $this->resetIfNewRequest();

        // Only this event's rows: a single question never reads the exceptions of every event.
        if (! array_key_exists($eventId, $this->overrides)) {
            $this->readOverrides([$eventId]);
        }

        return $this->overrides[$eventId];
    }

    /** @param list<int> $eventIds */
    private function readOverrides(array $eventIds): void
    {
        $keys = [];
        foreach ($eventIds as $id) {
            $keys[$id] = $this->key('overrides.' . $id);
        }

        $useCache = ! in_array(null, $keys, true);
        $cached = $useCache ? ($this->guard(fn () => Cache::many(array_values($keys))) ?? []) : [];
        $missing = array_values(array_filter($eventIds, fn (int $id) => ! is_array($cached[$keys[$id] ?? ''] ?? null)));

        $fresh = [];
        if ($missing) {
            $rows = EventModuleOverride::query()->current()->whereIn('event_id', $missing)
                ->get(['event_id', 'module_key', 'state', 'expires_at'])->groupBy('event_id');

            foreach ($missing as $id) {
                $fresh[$id] = isset($rows[$id])
                    ? $rows[$id]->map(fn ($o) => ['module_key' => $o->module_key, 'state' => $o->state, 'expires_at' => $o->expires_at?->getTimestamp()])->all()
                    : [];
            }

            if ($useCache) {
                $this->guard(fn () => Cache::putMany(array_combine(array_map(fn ($id) => $keys[$id], $missing), array_values($fresh)), self::CACHE_TTL));
            }
        }

        $now = now()->getTimestamp();
        foreach ($eventIds as $id) {
            $entries = $fresh[$id] ?? $cached[$keys[$id]];
            $current = array_filter($entries, fn (array $o) => $o['expires_at'] === null || $o['expires_at'] > $now);
            $this->overrides[$id] = array_column($current, 'state', 'module_key');
        }
    }

    /**
     * Enabled modules in menu order: by section, then by their own order.
     *
     * @return list<array{key: string, section: string, sort_order: int}>
     */
    private function catalog(): array
    {
        $this->resetIfNewRequest();

        $this->catalog ??= $this->bundle()['catalog'];

        // Fail closed, but loudly: an empty catalog locks every module, which is almost surely a missing seed.
        if ($this->catalog === [] && ! $this->warned) {
            $this->warned = true;
            Log::warning('The module catalog is empty: every module is locked. Run the migrations or `db:seed --class=ModuleSeeder`.');
        }

        return $this->catalog;
    }

    /** @return list<string> */
    private function includedKeys(?int $planId): array
    {
        $this->resetIfNewRequest();

        if (! $planId) {
            return [];
        }

        return $this->includedByPlan[$planId] ??= $this->bundle()['plans'][$planId] ?? [];
    }

    /**
     * The catalog and the modules of every plan, two small tables read together and cached under one key.
     *
     * @return array{catalog: list<array{key: string, section: string, sort_order: int}>, plans: array<int, list<string>>}
     */
    private function bundle(): array
    {
        return $this->bundle ??= $this->remember('catalog', function () {
            $catalog = Module::query()->enabled()->get()
                ->sortBy([
                    fn (Module $a, Module $b) => array_search($a->section, Module::SECTIONS, true) <=> array_search($b->section, Module::SECTIONS, true),
                    ['sort_order', 'asc'],
                ])
                ->map(fn (Module $m) => ['key' => $m->key, 'section' => $m->section, 'sort_order' => (int) $m->sort_order])
                ->values()->all();

            $plans = DB::table('module_plan')
                ->join('modules', 'modules.id', '=', 'module_plan.module_id')
                ->get(['module_plan.event_plan_id', 'modules.key'])
                ->groupBy('event_plan_id')
                ->map(fn ($rows) => $rows->pluck('key')->all())
                ->all();

            return ['catalog' => $catalog, 'plans' => $plans];
        }, cacheWhen: fn (array $value) => $value['catalog'] !== []);
    }

    /** `modules.{version}.{name}`: a new version makes every earlier key unreachable. Null: do not use the cache. */
    private function key(string $name): ?string
    {
        if ($this->bypass) {
            return null;
        }

        $this->version ??= $this->guard(function () {
            Cache::add(self::VERSION_KEY, (string) Str::uuid());

            return Cache::get(self::VERSION_KEY);
        });

        if (! is_string($this->version)) {
            // The store is down: answer from the database for the rest of the request, without asking it again.
            $this->bypass = true;

            return null;
        }

        return 'modules.' . $this->version . '.' . $name;
    }

    /**
     * Cache::remember with two differences: a store that fails does not fail the request (the database answers), and a
     * value the caller does not want kept (an empty catalog, a missing seed) is not kept.
     */
    private function remember(string $name, Closure $build, Closure $cacheWhen): mixed
    {
        $key = $this->key($name);
        $hit = $key === null ? null : $this->guard(fn () => Cache::get($key));

        if (is_array($hit)) {
            return $hit;
        }

        $value = $build();

        if ($key !== null && $cacheWhen($value)) {
            $this->guard(fn () => Cache::put($key, $value, self::CACHE_TTL));
        }

        return $value;
    }

    /** Run a cache call; a broken store reads as a miss and writes nothing. */
    private function guard(Closure $call): mixed
    {
        try {
            return $call();
        } catch (Throwable $e) {
            Log::warning('The module cache is not available: ' . $e->getMessage());

            return null;
        }
    }

    private function resetIfNewRequest(): void
    {
        $current = spl_object_id(app('request'));

        if ($this->requestId !== $current) {
            $this->requestId = $current;
            $this->version = null;
            $this->bypass = false;
            $this->bundle = null;
            $this->catalog = null;
            $this->includedByPlan = [];
            $this->overrides = [];
            $this->warned = false;
        }
    }
}
