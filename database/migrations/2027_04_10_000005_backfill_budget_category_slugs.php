<?php

use App\Http\Services\Budget\BudgetCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Starting budget categories carry a stable `slug` now (BudgetCatalog), which the assistant's vendor detectors read.
 * Existing default categories get theirs by matching the catalog name in es/en. A category with no match (the
 * organizer renamed it) stays without slug and its task is marked by hand. Idempotent: rows with a slug are skipped.
 */
return new class extends Migration
{
    public function up(): void
    {
        $catalog = new BudgetCatalog;

        DB::table('budget_categories')
            ->where('is_default', true)
            ->whereNull('slug')
            ->orderBy('id')
            ->each(function (object $row) use ($catalog) {
                $slug = $catalog->slugForName((string) $row->name);

                if ($slug !== null) {
                    DB::table('budget_categories')->where('id', $row->id)->update(['slug' => $slug]);
                }
            });
    }

    public function down(): void
    {
        // Data only: the slug is harmless once set.
    }
};
