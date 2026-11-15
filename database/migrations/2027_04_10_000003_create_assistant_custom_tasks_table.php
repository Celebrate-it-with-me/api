<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The organizer's own to-dos, listed in "Mi plan" next to the assistant's steps (plain text, max 50 per event). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_custom_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->string('title', 120);
            $table->string('notes', 500)->nullable();
            $table->date('due_date')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_custom_tasks');
    }
};
