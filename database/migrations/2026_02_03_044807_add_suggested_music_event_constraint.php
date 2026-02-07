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
        Schema::table('suggested_music', function (Blueprint $table) {
            // Prevent duplicate songs per event
            $table->unique(['event_id', 'platformId'], 'unique_song_per_event');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('suggested_music', function (Blueprint $table) {
            $table->dropUnique('unique_song_per_event');
        });
    }
};
