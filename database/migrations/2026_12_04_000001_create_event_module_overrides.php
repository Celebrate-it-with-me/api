<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-event module exceptions set by staff (admin Funciones tab): gift a locked module or lock one by hand,
 * with an optional expiry and a reason. `EventModules` reads them; an expired one is ignored and pruned later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_module_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->string('module_key', 60);
            $table->string('state', 10); // active | locked
            $table->timestamp('expires_at')->nullable();
            $table->string('reason', 500);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['event_id', 'module_key']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_module_overrides');
    }
};
