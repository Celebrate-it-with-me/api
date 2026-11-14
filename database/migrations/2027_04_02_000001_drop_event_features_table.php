<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The module catalog (modules, module_plan, event_module_overrides) is the only source of truth for what an event has.
 * Nothing reads event_features any more, and none of its flags has a counterpart that is not already in the catalog:
 * the mapped ones (rsvp, save_the_date, location, budget, menu, music, sweet_memories, event_comments,
 * seats_accommodation) are decided by the plan, and preview, analytics and background_music were never read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('event_features');
    }

    /** Recreates the empty table (the flags are not restored). */
    public function down(): void
    {
        if (Schema::hasTable('event_features')) {
            return;
        }

        Schema::create('event_features', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('event_id');
            $table->boolean('save_the_date')->default(false);
            $table->boolean('rsvp')->default(false);
            $table->boolean('sweet_memories')->default(false);
            $table->boolean('music')->default(false);
            $table->tinyInteger('background_music')->default(0);
            $table->tinyInteger('event_comments')->default(0);
            $table->boolean('seats_accommodation')->default(false);
            $table->boolean('preview')->default(false);
            $table->boolean('budget')->default(false);
            $table->boolean('analytics')->default(false);
            $table->boolean('menu')->default(false);
            $table->boolean('location')->default(false);
            $table->timestamps();
        });
    }
};
