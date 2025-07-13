<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Step 1: Add the order column
        Schema::table('sweet_memories', function (Blueprint $table) {
            $table->integer('order')->after('event_id')->default(0);
        });

        // Step 2: Assign unique order for each memory-event
        $events = DB::table('sweet_memories')
            ->select('event_id')
            ->distinct()
            ->get();

        foreach ($events as $event) {
            $memories = DB::table('sweet_memories')
                ->where('event_id', $event->event_id)
                ->orderBy('id')
                ->get();

            $order = 1;
            foreach ($memories as $memory) {
                DB::table('sweet_memories')
                    ->where('id', $memory->id)
                    ->update(['order' => $order]);
                $order++;
            }
        }

        // Step 3: Create unique constraint
        Schema::table('sweet_memories', function (Blueprint $table) {
            $table->unique(['event_id', 'order'], 'sweet_memories_event_id_order_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sweet_memories', function (Blueprint $table) {
            $table->dropUnique('sweet_memories_event_id_order_unique');
            $table->dropColumn('order');
        });
    }
};
