<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('event_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('description')->nullable();
            $table->integer('max_guests')->default(100);
            $table->string('slug')->nullable();

            // Plans Features available
            $table->boolean('has_gallery')->default(false);
            $table->boolean('has_music')->default(false);
            $table->boolean('has_custom_design')->default(false);
            $table->boolean('has_drag_editor')->default(false);
            $table->boolean('has_ai_assistant')->default(false);
            $table->boolean('has_invitations')->default(false);
            $table->boolean('has_sms')->default(false);
            $table->boolean('has_gift_registry')->default(false);
            $table->string('support_level')->default('basic'); // o 'priority'

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_plans');
    }
};
