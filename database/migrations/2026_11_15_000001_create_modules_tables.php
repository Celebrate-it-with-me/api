<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The module catalog. What the organizer's menu shows comes from here, not from code:
 *
 * - `modules.is_enabled` is the admin's global switch: off, the module is hidden for everyone
 *   (also how a module that is not built yet stays out of sight).
 * - `module_plan` says which plans include a module. An enabled module that the event's plan does
 *   not include is shown locked, with an upgrade prompt.
 *
 * The front end only knows how to draw each module (route, icon, text); which ones exist, in which
 * section and order, and who can use them is data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            // main | guests | organization | extras
            $table->string('section', 20);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('module_plan', function (Blueprint $table) {
            $table->foreignId('module_id')->constrained('modules')->cascadeOnDelete();
            $table->foreignId('event_plan_id')->constrained('event_plans')->cascadeOnDelete();
            $table->primary(['module_id', 'event_plan_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_plan');
        Schema::dropIfExists('modules');
    }
};
