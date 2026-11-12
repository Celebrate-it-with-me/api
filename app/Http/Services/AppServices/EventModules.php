<?php

namespace App\Http\Services\AppServices;

use App\Models\Events;
use App\Models\Module;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Which modules an event shows, and in what state, from the catalog in the database:
 *
 *   active  the event's plan includes the module
 *   locked  the module is enabled but the plan does not include it (shown with an upgrade prompt)
 *   (absent) the admin disabled the module: nobody sees it
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

    /** @var array<int, list<string>> */
    private array $includedByPlan = [];

    /** @return list<array{key: string, section: string, order: int, state: string}> */
    public function forEvent(Events $event): array
    {
        $included = $this->includedKeys($event->event_plan_id);

        return $this->catalog()
            ->map(fn (Module $module) => [
                'key' => $module->key,
                'section' => $module->section,
                'order' => $module->sort_order,
                'state' => in_array($module->key, $included, true) ? self::ACTIVE : self::LOCKED,
            ])
            ->values()
            ->all();
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

    /** Enabled modules in menu order: by section, then by their own order. */
    private function catalog(): Collection
    {
        $this->resetIfNewRequest();

        return $this->catalog ??= Module::query()->enabled()->get()
            ->sortBy([
                fn (Module $a, Module $b) => array_search($a->section, Module::SECTIONS, true) <=> array_search($b->section, Module::SECTIONS, true),
                ['sort_order', 'asc'],
            ])
            ->values();
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
        }
    }
}
