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
        Schema::create('save_the_date', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('event_id');
            $table->string('std_title')->nullable();
            $table->string('std_subtitle')->nullable();
            $table->string('background_color')->nullable();
            $table->string('image_url')->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->boolean('use_countdown')->default(false);
            $table->boolean('use_add_to_calendar')->default(false);
            $table->foreign('event_id')->references('id')->on('events')->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('save_the_date');
    }
};
