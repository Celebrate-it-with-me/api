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
        Schema::create('event_location_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_location_id')
                ->references('id')
                ->on('event_locations')
                ->onDelete('cascade');

            $table->text('path');
            $table->string('caption')->nullable();
            $table->integer('order')->default(0);
            $table->string('source')->default('uploaded');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_location_images');
    }
};
