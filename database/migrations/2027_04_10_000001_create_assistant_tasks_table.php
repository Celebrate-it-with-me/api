<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The catalog of steps the assistant proposes (docs/plans/assistant-contract.md). Seeded es/en; editable later by the admin. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();
            $table->string('event_type', 12); // quinceanera | wedding | both
            $table->string('phase', 16); // foundation | planning | details | invitations | final | after
            $table->smallInteger('sort_order')->default(0);
            $table->decimal('start_months', 4, 1);
            $table->decimal('late_months', 4, 1);
            $table->json('prerequisites');
            $table->string('detector', 40)->nullable();
            $table->string('module_key', 40)->nullable();
            $table->string('route', 120);
            $table->string('title_es', 120);
            $table->string('title_en', 120);
            $table->string('why_es', 280);
            $table->string('why_en', 280);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_tasks');
    }
};
