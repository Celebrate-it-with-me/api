<?php

namespace Database\Seeders;

use App\Models\EventPlan;
use App\Models\Module;
use Illuminate\Database\Seeder;

/**
 * Starting catalog of modules. It only creates what is missing: once a module exists, whatever the
 * admin changed (switch, order, which plans include it) is never overwritten by a new deploy.
 */
class ModuleSeeder extends Seeder
{
    /**
     * key => [section, order, enabled, premium].
     * `premium` modules are included only in the plans above the entry one; the rest are in every plan.
     * Modules that are not built yet start disabled: turning the switch on is how they go live.
     */
    public const CATALOG = [
        'invitation' => ['main', 10, false, false],

        'guests' => ['guests', 10, true, false],
        'rsvp' => ['guests', 20, true, false],
        'save_the_date' => ['guests', 30, true, false],

        'timeline' => ['organization', 10, true, false],
        'locations' => ['organization', 20, true, false],
        'dress_code' => ['organization', 30, true, false],
        'budget' => ['organization', 40, true, false],
        'padrinos' => ['organization', 50, true, false],

        'menus' => ['extras', 10, true, true],
        'seating' => ['extras', 20, true, true],
        'suggested_music' => ['extras', 30, true, true],
        'memories' => ['extras', 40, true, true],
        'comments' => ['extras', 50, true, true],
    ];

    public function run(): void
    {
        $plans = EventPlan::query()->orderBy('id')->get();
        $entryPlan = $plans->first();

        foreach (self::CATALOG as $key => [$section, $order, $enabled, $premium]) {
            $module = Module::query()->firstOrCreate(
                ['key' => $key],
                ['section' => $section, 'sort_order' => $order, 'is_enabled' => $enabled],
            );

            if (! $module->wasRecentlyCreated) {
                continue;
            }

            $included = $premium ? $plans->where('id', '!=', $entryPlan?->id) : $plans;
            $module->plans()->syncWithoutDetaching($included->pluck('id')->all());
        }
    }
}
