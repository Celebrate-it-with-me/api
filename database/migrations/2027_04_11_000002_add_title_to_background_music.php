<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('background_music', function (Blueprint $table) {
            // Name of the song as the organizer wants it shown (accessible name of the player button).
            $table->string('title', 60)->nullable()->after('song_url');
        });
    }

    public function down(): void
    {
        Schema::table('background_music', function (Blueprint $table) {
            $table->dropColumn('title');
        });
    }
};
