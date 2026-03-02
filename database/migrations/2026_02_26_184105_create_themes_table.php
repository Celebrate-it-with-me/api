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
        Schema::create('themes', function (Blueprint $table) {
            $table->id();
            
            $table->string('name');
            $table->string('slug')->unique();
            
            // Free/Pro/Lux gating
            $table->string('tier')->default('free');
            $table->boolean('is_published')->default(true);
            
            // Optional: base config for default (Can be merged with event_theme config)
            $table->json('default_config')->nullable();
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('themes');
    }
};
