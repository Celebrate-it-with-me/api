<?php

namespace App\Http\Services\Admin\Health;

use App\Models\Module;

/** Read-only view of the module catalog for the health screen (the catalog itself is edited elsewhere). */
class ModuleFlags
{
    public function report(): array
    {
        $modules = Module::query()->with('plans:id,slug')->orderBy('section')->orderBy('sort_order')->get();

        return [
            'total' => $modules->count(),
            'enabled' => $modules->where('is_enabled', true)->count(),
            'disabled' => $modules->where('is_enabled', false)->count(),
            'items' => $modules->map(fn (Module $m) => [
                'key' => $m->key,
                'section' => $m->section,
                'isEnabled' => $m->is_enabled,
                'plans' => $m->plans->pluck('slug')->sort()->values(),
            ])->values(),
        ];
    }
}
