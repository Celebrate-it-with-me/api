<?php

use App\Models\EventPlan;
use App\Models\Events;
use App\Models\Module;
use Database\Seeders\EventPlanSeeder;
use Database\Seeders\ModuleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `module.access` fails closed: with no catalog, or a plan with no modules, everything is locked. This makes sure
 * a deployed database has what it needs: plans, the starting catalog, each plan's modules, and a plan on every event.
 *
 * It only creates what is missing; what the admin changed (switches, order, which plans include a module) stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! EventPlan::query()->exists()) {
            (new EventPlanSeeder)->run();
        }

        // Creates only the missing modules (and gives them to the plans per the starting rules).
        (new ModuleSeeder)->run();

        $entryId = EventPlan::query()->orderBy('id')->value('id');
        $premiumKeys = collect(ModuleSeeder::CATALOG)->filter(fn (array $row) => $row[3])->keys()->all();

        // A plan with no module at all (created after the seed, or never assigned): give it the starting set.
        foreach (EventPlan::query()->orderBy('id')->get() as $plan) {
            if (DB::table('module_plan')->where('event_plan_id', $plan->id)->exists()) {
                continue;
            }

            $moduleIds = Module::query()
                ->when($plan->id === $entryId, fn ($q) => $q->whereNotIn('key', $premiumKeys))
                ->pluck('id');

            DB::table('module_plan')->insert($moduleIds->map(fn ($id) => ['module_id' => $id, 'event_plan_id' => $plan->id])->all());
        }

        // An event without a plan would have every module locked: it starts on the entry plan.
        if ($entryId) {
            Events::withoutGlobalScopes()->whereNull('event_plan_id')->update(['event_plan_id' => $entryId]);
        }
    }

    public function down(): void
    {
        // Data repair only: nothing to undo.
    }
};
