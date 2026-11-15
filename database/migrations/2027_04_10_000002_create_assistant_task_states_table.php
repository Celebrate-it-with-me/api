<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** What the organizer did with a step of her event: one row per (event, task); acting again replaces it. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_task_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            // Soft key (no FK to assistant_tasks): a task the admin switches off leaves a harmless row behind.
            $table->string('task_key', 60);
            $table->string('state', 16); // done | hidden | not_applicable | snoozed
            $table->timestamp('snoozed_until')->nullable();
            $table->foreignId('set_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['event_id', 'task_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_task_states');
    }
};
