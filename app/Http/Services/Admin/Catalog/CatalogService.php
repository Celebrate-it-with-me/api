<?php

namespace App\Http\Services\Admin\Catalog;

use App\Http\Services\Admin\AdminAudit;
use App\Http\Services\AppServices\EventModules;
use App\Http\Services\AppServices\Hydrate\EventCacheService;
use App\Http\Services\Payments\PurchaseService;
use App\Models\EventModuleOverride;
use App\Models\EventPlan;
use App\Models\Events;
use App\Models\Module;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** The module catalog as the admin sees it: which plans include each module, what a change would do, and the change. */
class CatalogService
{
    public function __construct(private readonly EventModules $modules, private readonly AdminAudit $audit) {}

    public function overview(): array
    {
        $plans = EventPlan::query()->orderBy('id')->get();
        $eventsByPlan = $this->eventsByPlan();
        $slugsByModule = $this->planSlugsByModule($plans);
        $overrides = $this->overrideCounts();

        $modules = Module::query()->get()
            ->sortBy([
                fn (Module $a, Module $b) => array_search($a->section, Module::SECTIONS, true) <=> array_search($b->section, Module::SECTIONS, true),
                ['sort_order', 'asc'],
            ])
            ->values()
            ->map(function (Module $m) use ($slugsByModule, $eventsByPlan, $overrides) {
                $slugs = $slugsByModule[$m->id] ?? [];

                return [
                    'key' => $m->key,
                    'section' => $m->section,
                    'order' => $m->sort_order,
                    'isEnabled' => $m->is_enabled,
                    'planSlugs' => $slugs,
                    'eventsByPlan' => collect($slugs)->mapWithKeys(fn (string $s) => [$s => $eventsByPlan[$s] ?? 0])->all(),
                    'overrides' => $overrides[$m->key] ?? ['active' => 0, 'locked' => 0],
                ];
            });

        return [
            'modules' => $modules,
            'plans' => $plans->map(fn (EventPlan $p) => $this->planRow($p, $eventsByPlan[$p->slug] ?? 0))->values(),
        ];
    }

    /**
     * What would change if the module were included in exactly these plans: per changed plan, the events that go from
     * active to locked and from locked to active. Events with a current exception for the module do not change.
     *
     * @param  list<string>  $newSlugs
     */
    public function impact(string $key, array $newSlugs): array
    {
        $module = Module::query()->where('key', $key)->firstOrFail();
        $plans = EventPlan::query()->orderBy('id')->get();
        $current = $this->planSlugsByModule($plans)[$module->id] ?? [];

        $eventsByPlan = $this->eventsByPlan();
        $withOverride = $this->eventsWithCurrentOverrideByPlan($key);

        $rows = [];
        foreach ($plans as $plan) {
            $was = in_array($plan->slug, $current, true);
            $will = in_array($plan->slug, $newSlugs, true);
            if ($was === $will) {
                continue;
            }

            $total = $eventsByPlan[$plan->slug] ?? 0;
            $kept = $withOverride[$plan->slug] ?? 0;
            $affected = $total - $kept;

            $rows[] = [
                'slug' => $plan->slug,
                'name' => $plan->name,
                'change' => $will ? 'added' : 'removed',
                'events' => $total,
                'withOverride' => $kept,
                'toLocked' => $will ? 0 : $affected,
                'toActive' => $will ? $affected : 0,
            ];
        }

        return [
            'module' => $key,
            'isEnabled' => $module->is_enabled,
            'currentPlanSlugs' => $current,
            'planSlugs' => array_values($newSlugs),
            'removesFromFullPlan' => in_array(PurchaseService::FULL_SLUG, $current, true) && ! in_array(PurchaseService::FULL_SLUG, $newSlugs, true),
            'plans' => $rows,
            'totalToLocked' => array_sum(array_column($rows, 'toLocked')),
            'totalToActive' => array_sum(array_column($rows, 'toActive')),
        ];
    }

    /** @param  list<string>  $newSlugs */
    public function updatePlans(string $key, array $newSlugs, string $reason, bool $keepForExisting): array
    {
        $module = Module::query()->where('key', $key)->firstOrFail();
        $plans = EventPlan::query()->whereIn('slug', $newSlugs)->get();
        $allPlans = EventPlan::query()->get();
        $currentIds = DB::table('module_plan')->where('module_id', $module->id)->pluck('event_plan_id')->all();
        $fullPlan = $allPlans->firstWhere('slug', PurchaseService::FULL_SLUG);

        if ($fullPlan && in_array($fullPlan->id, $currentIds, true) && ! $plans->contains('id', $fullPlan->id)) {
            throw new HttpResponseException(response()->json([
                'message' => 'Un módulo del plan completo no se puede quitar: el plan promete todo desbloqueado.',
                'code' => 'module_in_sold_plan',
                'errors' => ['planSlugs' => ['El módulo no puede salir del plan completo.']],
            ], 422));
        }

        $newIds = $plans->pluck('id')->all();
        $removedIds = array_values(array_diff($currentIds, $newIds));
        $addedIds = array_values(array_diff($newIds, $currentIds));
        $before = $this->planSlugsByModule($allPlans)[$module->id] ?? [];

        $created = 0;
        $affected = 0;

        DB::transaction(function () use ($module, $newIds, $removedIds, $keepForExisting, $reason, &$created, &$affected) {
            if ($keepForExisting && $removedIds) {
                $created = $this->keepForExisting($module->key, $removedIds, $reason);
            }
            $affected = $this->countEventsWithoutOverride($module->key, $removedIds);

            $module->plans()->sync($newIds);
        });

        $this->modules->forget();

        $after = $this->planSlugsByModule(EventPlan::query()->get())[$module->id] ?? [];

        $this->audit->record('catalog.module_plans_updated', $module, [
            'reason' => $reason,
            'before' => ['planSlugs' => $before],
            'after' => [
                'planSlugs' => $after,
                'keepForExisting' => $keepForExisting,
                'overridesCreated' => $created,
                'eventsLocked' => $keepForExisting ? 0 : $affected,
                'addedPlans' => count($addedIds),
                'removedPlans' => count($removedIds),
            ],
        ]);

        return [
            'module' => $this->moduleRow($module->fresh()),
            'overridesCreated' => $created,
            'eventsLocked' => $keepForExisting ? 0 : $affected,
        ];
    }

    /** Events of the plan whose guest list is above `$limit` (0 = unlimited, so none). Nobody is blocked: it is information. */
    public function eventsOverLimit(EventPlan $plan, int $limit): int
    {
        if ($limit <= 0) {
            return 0;
        }

        return Events::query()->where('events.event_plan_id', $plan->id)
            ->whereRaw('(select count(*) from guests where guests.event_id = events.id and guests.deleted_at is null) > ?', [$limit])
            ->count();
    }

    /** @return array{slug: string, maxGuests: int, eventsNowOver: int, eventsOverNewLimit: int} */
    public function planImpact(string $slug, int $maxGuests): array
    {
        $plan = EventPlan::query()->where('slug', $slug)->firstOrFail();

        return [
            'slug' => $plan->slug,
            'maxGuests' => $maxGuests,
            'eventsNowOver' => $this->eventsOverLimit($plan, (int) $plan->max_guests),
            'eventsOverNewLimit' => $this->eventsOverLimit($plan, $maxGuests),
        ];
    }

    /**
     * Edits name, description and guest limit of a plan. The limits of the sold plans stay coherent: the full plan
     * is never below 1 (unless unlimited) and the free plan never exceeds it.
     *
     * @param  array{name?: string, description?: ?string, maxGuests?: int}  $changes
     */
    public function updatePlan(string $slug, array $changes, string $reason): array
    {
        $plan = EventPlan::query()->where('slug', $slug)->firstOrFail();
        $before = ['name' => $plan->name, 'description' => $plan->description, 'maxGuests' => (int) $plan->max_guests];

        $after = $before;
        foreach (['name', 'description', 'maxGuests'] as $field) {
            if (array_key_exists($field, $changes)) {
                $after[$field] = $changes[$field];
            }
        }

        $this->assertCoherentLimits($plan, (int) $after['maxGuests']);

        $plan->update(['name' => $after['name'], 'description' => $after['description'], 'max_guests' => (int) $after['maxGuests']]);
        app(EventCacheService::class)->clearPlans();

        $over = $this->eventsOverLimit($plan, (int) $after['maxGuests']);

        $this->audit->record('catalog.plan_updated', $plan, [
            'reason' => $reason,
            'before' => $before,
            'after' => $after + ['eventsOverNewLimit' => $over],
        ]);

        $row = $this->planRow($plan->fresh(), Events::query()->where('event_plan_id', $plan->id)->count());
        $row['eventsOverNewLimit'] = $over;

        return $row;
    }

    private function planRow(EventPlan $p, int $events): array
    {
        return [
            'slug' => $p->slug,
            'name' => $p->name,
            'maxGuests' => $p->max_guests,
            'priceCents' => $p->price_cents,
            'isFullPlan' => $p->slug === PurchaseService::FULL_SLUG,
            'eventsCount' => $events,
        ];
    }

    private function assertCoherentLimits(EventPlan $plan, int $newLimit): void
    {
        $full = $plan->slug === PurchaseService::FULL_SLUG ? $newLimit : (int) EventPlan::query()->where('slug', PurchaseService::FULL_SLUG)->value('max_guests');
        $free = $plan->slug === PurchaseService::FREE_SLUG ? $newLimit : (int) EventPlan::query()->where('slug', PurchaseService::FREE_SLUG)->value('max_guests');

        $problem = null;
        if ($plan->slug === PurchaseService::FULL_SLUG && $full !== 0 && $full < 1) {
            $problem = 'El plan completo no puede tener un límite menor a 1.';
        } elseif (in_array($plan->slug, [PurchaseService::FREE_SLUG, PurchaseService::FULL_SLUG], true) && $full !== 0 && ($free === 0 || $free > $full)) {
            $problem = 'El plan gratuito no puede superar al plan completo.';
        }

        if ($problem) {
            throw new HttpResponseException(response()->json([
                'message' => $problem,
                'code' => 'incoherent_plan_limits',
                'errors' => ['maxGuests' => [$problem]],
            ], 422));
        }
    }

    public function toggle(string $key, bool $enabled, string $reason): array
    {
        $module = Module::query()->where('key', $key)->firstOrFail();
        $before = ['isEnabled' => $module->is_enabled];

        $module->update(['is_enabled' => $enabled]);
        $this->modules->forget();

        $orphans = EventModuleOverride::query()->current()->where('module_key', $key)->count();

        $this->audit->record('catalog.module_toggled', $module, [
            'reason' => $reason,
            'before' => $before,
            'after' => ['isEnabled' => $enabled, 'orphanOverrides' => $orphans],
        ]);

        return [
            'module' => $this->moduleRow($module->fresh()),
            'orphanOverrides' => $orphans,
        ];
    }

    /** Gives an `active` exception to every event of the plans that had the module and has no current exception. */
    private function keepForExisting(string $key, array $planIds, string $reason): int
    {
        $actorId = request()->user()?->id;
        $created = 0;
        $now = now();

        Events::query()->whereIn('event_plan_id', $planIds)->select('id')
            ->chunkById(500, function (Collection $events) use ($key, $reason, $actorId, $now, &$created) {
                $ids = $events->pluck('id')->all();

                $existing = EventModuleOverride::query()->where('module_key', $key)->whereIn('event_id', $ids)->get(['id', 'event_id', 'expires_at']);
                $current = $existing->filter(fn ($o) => $o->expires_at === null || $o->expires_at->isFuture())->pluck('event_id')->all();
                $expiredIds = $existing->reject(fn ($o) => in_array($o->event_id, $current, true))->pluck('id')->all();

                if ($expiredIds) {
                    EventModuleOverride::query()->whereIn('id', $expiredIds)->delete();
                }

                $rows = collect($ids)->diff($current)->map(fn (int $id) => [
                    'event_id' => $id,
                    'module_key' => $key,
                    'state' => EventModules::ACTIVE,
                    'expires_at' => null,
                    'reason' => $reason,
                    'created_by' => $actorId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->values()->all();

                if ($rows) {
                    EventModuleOverride::query()->insert($rows);
                    $created += count($rows);
                }
            }, 'id');

        return $created;
    }

    /** Events in the given plans without a current exception for the module (before any exception is created). */
    private function countEventsWithoutOverride(string $key, array $planIds): int
    {
        if (! $planIds) {
            return 0;
        }

        return Events::query()->whereIn('event_plan_id', $planIds)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('event_module_overrides as o')
                ->whereColumn('o.event_id', 'events.id')->where('o.module_key', $key)
                ->where(fn ($w) => $w->whereNull('o.expires_at')->orWhere('o.expires_at', '>', now())))
            ->count();
    }

    private function moduleRow(Module $m): array
    {
        $plans = EventPlan::query()->orderBy('id')->get();
        $slugs = $this->planSlugsByModule($plans)[$m->id] ?? [];
        $eventsByPlan = $this->eventsByPlan();

        return [
            'key' => $m->key,
            'section' => $m->section,
            'order' => $m->sort_order,
            'isEnabled' => $m->is_enabled,
            'planSlugs' => $slugs,
            'eventsByPlan' => collect($slugs)->mapWithKeys(fn (string $s) => [$s => $eventsByPlan[$s] ?? 0])->all(),
            'overrides' => $this->overrideCounts()[$m->key] ?? ['active' => 0, 'locked' => 0],
        ];
    }

    /** @return array<string, int> plan slug => events */
    private function eventsByPlan(): array
    {
        return Events::query()->join('event_plans', 'event_plans.id', '=', 'events.event_plan_id')
            ->groupBy('event_plans.slug')
            ->selectRaw('event_plans.slug as slug, count(*) as total')
            ->pluck('total', 'slug')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /** @return array<string, int> plan slug => events of that plan with a current exception for the module */
    private function eventsWithCurrentOverrideByPlan(string $key): array
    {
        return EventModuleOverride::query()->current()
            ->join('events', 'events.id', '=', 'event_module_overrides.event_id')
            ->whereNull('events.deleted_at')
            ->join('event_plans', 'event_plans.id', '=', 'events.event_plan_id')
            ->where('event_module_overrides.module_key', $key)
            ->groupBy('event_plans.slug')
            ->selectRaw('event_plans.slug as slug, count(*) as total')
            ->pluck('total', 'slug')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /** @return array<string, array{active: int, locked: int}> */
    private function overrideCounts(): array
    {
        $out = [];
        $rows = EventModuleOverride::query()->current()
            ->join('events', 'events.id', '=', 'event_module_overrides.event_id')
            ->whereNull('events.deleted_at')
            ->groupBy('event_module_overrides.module_key', 'event_module_overrides.state')
            ->selectRaw('event_module_overrides.module_key as module_key, event_module_overrides.state as state, count(*) as total')
            ->get();

        foreach ($rows as $r) {
            $out[$r->module_key] ??= ['active' => 0, 'locked' => 0];
            $out[$r->module_key][$r->state] = (int) $r->total;
        }

        return $out;
    }

    /** @return array<int, list<string>> module id => plan slugs */
    private function planSlugsByModule(Collection $plans): array
    {
        $slugById = $plans->pluck('slug', 'id');

        return DB::table('module_plan')->get()
            ->groupBy('module_id')
            ->map(fn (Collection $rows) => $rows->map(fn ($r) => $slugById[$r->event_plan_id] ?? null)->filter()->sort()->values()->all())
            ->all();
    }
}
