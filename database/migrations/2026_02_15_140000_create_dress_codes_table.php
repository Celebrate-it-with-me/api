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
        Schema::create('dress_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->onDelete('cascade');
            $table->enum('dress_code_type', ['formal', 'semi-formal', 'casual', 'thematic', 'black-tie']);
            $table->text('description')->nullable();
            $table->json('reserved_colors');
            $table->timestamps();

            $table->index('event_id', 'idx_event_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dress_codes');
    }
};
