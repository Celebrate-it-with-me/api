<?php

namespace App\Http\Services\Admin\Events;

use App\Http\Services\Admin\AdminAudit;
use App\Http\Services\AppServices\EventModules;
use App\Models\EventModuleOverride;
use App\Models\Events;
use App\Models\Module;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/** Staff exceptions to what an event's plan says about a module (admin Funciones tab). */
class ModuleOverrides
{
    public function __construct(private readonly EventModules $modules, private readonly AdminAudit $audit) {}

    public function list(Events $event): array
    {
        $this->modules->forget();
        // One query whatever the number of exceptions: the author's name comes with a join.
        $overrides = EventModuleOverride::query()->current()
            ->leftJoin('users', 'users.id', '=', 'event_module_overrides.created_by')
            ->where('event_module_overrides.event_id', $event->id)
            ->get(['event_module_overrides.*', 'users.name as creator_name'])
            ->keyBy('module_key');

        $rows = collect($this->modules->detailedForEvent($event))->map(function (array $m) use ($overrides) {
            $o = $overrides->get($m['key']);

            return [
                'key' => $m['key'],
                'section' => $m['section'],
                'order' => $m['order'],
                'baseState' => $m['base'],
                'effectiveState' => $m['state'],
                'source' => $o ? ($o->state === EventModules::ACTIVE ? 'gift' : 'blocked') : 'plan',
                'override' => $o ? $this->present($o) : null,
            ];
        })->values();

        return [
            'giftsActive' => $rows->where('source', 'gift')->count(),
            'blockedActive' => $rows->where('source', 'blocked')->count(),
            'modules' => $rows,
        ];
    }

    public function set(Events $event, string $key, string $state, ?CarbonInterface $expiresAt, string $reason): array
    {
        $this->ensureEnabled($key);

        $existing = EventModuleOverride::query()->where('event_id', $event->id)->where('module_key', $key)->first();
        $before = $this->snapshot($event, $key, $existing);

        $override = EventModuleOverride::query()->updateOrCreate(
            ['event_id' => $event->id, 'module_key' => $key],
            ['state' => $state, 'expires_at' => $expiresAt, 'reason' => $reason, 'created_by' => request()->user()?->id],
        );
        $this->modules->forget();

        $this->audit->record('event.module_override', $event, [
            'reason' => $reason,
            'before' => $before,
            'after' => $this->snapshot($event, $key, $override),
        ]);

        return $this->list($event);
    }

    public function remove(Events $event, string $key, string $reason): array
    {
        $override = EventModuleOverride::query()->where('event_id', $event->id)->where('module_key', $key)->first();
        abort_if($override === null, 404, 'This module has no exception.');

        $before = $this->snapshot($event, $key, $override);
        $override->delete();
        $this->modules->forget();

        $this->audit->record('event.module_override_removed', $event, [
            'reason' => $reason,
            'before' => $before,
            'after' => $this->snapshot($event, $key, null),
        ]);

        return $this->list($event);
    }

    private function ensureEnabled(string $key): void
    {
        if (! Module::query()->enabled()->where('key', $key)->exists()) {
            throw ValidationException::withMessages(['module' => 'Este módulo no existe o está desactivado en el catálogo.']);
        }
    }

    private function snapshot(Events $event, string $key, ?EventModuleOverride $o): array
    {
        $this->modules->forget();
        $current = $o && ($o->expires_at === null || $o->expires_at->isFuture()) ? $o->state : null;

        return [
            'module' => $key,
            'override' => $o ? ['state' => $o->state, 'expiresAt' => $o->expires_at?->toIso8601String(), 'reason' => $o->reason] : null,
            'effectiveState' => $current ?? collect($this->modules->detailedForEvent($event))->firstWhere('key', $key)['base'] ?? null,
        ];
    }

    private function present(EventModuleOverride $o): array
    {
        return [
            'state' => $o->state,
            'reason' => $o->reason,
            'expiresAt' => $o->expires_at?->toIso8601String(),
            'createdAt' => $o->updated_at?->toIso8601String(),
            'createdBy' => $o->created_by ? ['id' => $o->created_by, 'name' => $o->creator_name] : null,
        ];
    }
}
