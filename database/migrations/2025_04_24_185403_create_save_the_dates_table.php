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
        Schema::create('save_the_dates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->onDelete('cascade');
            
            $table->string('title');
            $table->text('message')->nullable();
            $table->string('image_path')->nullable();
            $table->string('video_url')->nullable();
            
            $table->boolean('show_countdown')->default(false); // Whether to show a countdown timer
            $table->boolean('show_add_to_calendar')->default(false); // Whether to show an "Add to Calendar" button
            
            $table->json('styles')->nullable(); // JSON field for custom styles
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('save_the_dates');
    }
};
