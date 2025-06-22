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
        Schema::create('sweet_memories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')
                ->comment('Foreign key referencing the events table')
                ->constrained('events')
                ->onDelete('cascade');
            $table->string('title')->comment('Title of the sweet memory');
            $table->text('description')->nullable()->comment('Description of the sweet memory');
            $table->string('year')->nullable()->comment('Year when the memory was created or occurred');
            $table->boolean('visible')->default(true)->comment('Visibility status of the sweet memory');
            $table->string('image_path')->nullable()->comment('Path to the image associated with the sweet memory');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sweet_memories');
    }
};
