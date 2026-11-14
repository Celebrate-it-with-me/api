<?php

namespace App\Http\Services\AppServices;

use App\Models\EventModuleOverride;
use App\Models\Events;
use App\Models\Module;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
 * The answer is kept only while the same request is being handled, so a list of events costs two
 * queries, not two per event, and nothing goes stale between requests.
 */
class EventModules
{
    public const ACTIVE = 'active';

    public const LOCKED = 'locked';

    private ?int $requestId = null;

    private ?Collection $catalog = null;

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

        return $this->catalog()
            ->map(function (Module $module) use ($included, $overrides) {
                $base = in_array($module->key, $included, true) ? self::ACTIVE : self::LOCKED;

                return [
                    'key' => $module->key,
                    'section' => $module->section,
                    'order' => $module->sort_order,
                    'state' => $overrides[$module->key] ?? $base,
                    'base' => $base,
                    'overridden' => isset($overrides[$module->key]),
                ];
            })
            ->values()
            ->all();
    }

    /** Drop what was read in this request (after a staff exception changed). */
    public function forget(): void
    {
        $this->overrides = [];
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
        $rows = EventModuleOverride::query()->current()->whereIn('event_id', $eventIds)
            ->get(['event_id', 'module_key', 'state'])->groupBy('event_id');

        foreach ($eventIds as $id) {
            $this->overrides[$id] = isset($rows[$id]) ? $rows[$id]->pluck('state', 'module_key')->all() : [];
        }
    }

    /** Enabled modules in menu order: by section, then by their own order. */
    private function catalog(): Collection
    {
        $this->resetIfNewRequest();

        $this->catalog ??= Module::query()->enabled()->get()
            ->sortBy([
                fn (Module $a, Module $b) => array_search($a->section, Module::SECTIONS, true) <=> array_search($b->section, Module::SECTIONS, true),
                ['sort_order', 'asc'],
            ])
            ->values();

        // Fail closed, but loudly: an empty catalog locks every module, which is almost surely a missing seed.
        if ($this->catalog->isEmpty() && ! $this->warned) {
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

        return $this->includedByPlan[$planId] ??= DB::table('module_plan')
            ->join('modules', 'modules.id', '=', 'module_plan.module_id')
            ->where('module_plan.event_plan_id', $planId)
            ->pluck('modules.key')
            ->all();
    }

    private function resetIfNewRequest(): void
    {
        $current = spl_object_id(app('request'));

        if ($this->requestId !== $current) {
            $this->requestId = $current;
            $this->catalog = null;
            $this->includedByPlan = [];
            $this->overrides = [];
            $this->warned = false;
        }
    }
}
