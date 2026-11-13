<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** English names of the old global categories, as the Spanish names they become in each budget. */
    private const OLD_NAMES = [
        'venue' => 'Salón', 'catering' => 'Banquete', 'decoration' => 'Decoración', 'entertainment' => 'Música y entretenimiento',
        'photography' => 'Fotografía y video', 'outfits' => 'Vestuario', 'transportation' => 'Transporte', 'miscellaneous' => 'Otros',
    ];

    public function up(): void
    {
        // Categories belong to one event's budget now (copied from a catalog, editable).
        Schema::table('budget_categories', function (Blueprint $table) {
            $table->foreignId('event_budget_id')->nullable()->after('id')->constrained('event_budgets')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0)->after('name');
        });
        Schema::table('budget_categories', function (Blueprint $table) {
            $table->dropUnique(['slug']);
        });
        Schema::table('budget_categories', function (Blueprint $table) {
            $table->string('slug')->nullable()->change();
        });

        Schema::table('budget_items', function (Blueprint $table) {
            // What was agreed with the vendor (quote or contract). The item owes this, or its estimate if there is none yet.
            $table->decimal('agreed_cost', 12, 2)->nullable()->after('estimated_cost');
            $table->string('vendor_name')->nullable()->after('description');
            $table->string('vendor_phone', 30)->nullable()->after('vendor_name');
        });

        Schema::create('budget_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_item_id')->constrained('budget_items')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('paid_on');
            $table->string('note')->nullable();
            // Who paid: the family (null) or a padrino (FK added with the padrinos table).
            $table->unsignedBigInteger('padrino_id')->nullable()->index();
            $table->timestamps();
        });

        $this->convertData();

        Schema::table('budget_items', function (Blueprint $table) {
            $table->dropColumn(['actual_cost', 'is_paid']);
        });
    }

    /** Old rows become the new shape: per-budget Spanish categories, agreed cost, and a payment for what was paid. */
    private function convertData(): void
    {
        $old = DB::table('budget_categories')->whereNull('event_budget_id')->get()->keyBy('id');

        foreach (DB::table('event_budgets')->pluck('id') as $budgetId) {
            $usedIds = DB::table('budget_items')->where('event_budget_id', $budgetId)->whereNotNull('category_id')->distinct()->pluck('category_id');
            foreach ($usedIds as $oldId) {
                $row = $old[$oldId] ?? null;
                if (! $row) {
                    continue;
                }
                $newId = DB::table('budget_categories')->insertGetId([
                    'event_budget_id' => $budgetId,
                    'name' => self::OLD_NAMES[$row->slug] ?? $row->name,
                    'slug' => $row->slug,
                    'sort_order' => 0,
                    'is_default' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('budget_items')->where('event_budget_id', $budgetId)->where('category_id', $oldId)->update(['category_id' => $newId]);
            }
        }

        DB::table('budget_items')->whereNotNull('actual_cost')->update(['agreed_cost' => DB::raw('actual_cost')]);

        foreach (DB::table('budget_items')->where('is_paid', true)->get() as $item) {
            DB::table('budget_payments')->insert([
                'budget_item_id' => $item->id,
                'amount' => $item->actual_cost ?? $item->estimated_cost,
                'paid_on' => $item->due_date ?? substr((string) $item->updated_at, 0, 10),
                'note' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('budget_categories')->whereNull('event_budget_id')->delete();
    }

    public function down(): void
    {
        Schema::table('budget_items', function (Blueprint $table) {
            $table->decimal('actual_cost', 12, 2)->nullable();
            $table->boolean('is_paid')->default(false);
        });
        DB::table('budget_items')->update(['actual_cost' => DB::raw('agreed_cost')]);
        Schema::dropIfExists('budget_payments');
        Schema::table('budget_items', fn (Blueprint $table) => $table->dropColumn(['agreed_cost', 'vendor_name', 'vendor_phone']));
        Schema::table('budget_categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('event_budget_id');
            $table->dropColumn('sort_order');
            $table->unique('slug');
        });
    }
};
