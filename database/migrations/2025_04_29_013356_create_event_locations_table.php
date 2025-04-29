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
        Schema::create('event_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->onDelete('cascade');
            $table->string('name'); // Name of the location
            $table->string('address'); // Street address
            $table->string('city'); // City
            $table->string('state'); // State or province
            $table->string('zip_code'); // Postal code
            $table->string('country'); // Country
            $table->string('image_path')->nullable(); // Path to an image of the location
            $table->text('notes')->nullable(); // Additional notes about the location
            $table->string('phone')->nullable(); // Phone number for the location
            $table->decimal('latitude', 10, 8)->nullable(); // Latitude for geolocation
            $table->decimal('longitude', 11, 8)->nullable(); // Longitude for geolocation
            $table->boolean('is_default')->default(false); // Whether this is the default location for the event
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_locations');
    }
};
