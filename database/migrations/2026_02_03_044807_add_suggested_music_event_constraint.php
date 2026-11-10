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
        // 1) Remove duplicates keeping the smallest id per (event_id, platformId)
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("
                DELETE FROM suggested_music sm
                USING suggested_music sm2
                WHERE sm.event_id = sm2.event_id
                  AND sm.\"platformId\" = sm2.\"platformId\"
                  AND sm.id > sm2.id
            ");
        } else { // mysql / mariadb
            DB::statement("
                DELETE sm FROM suggested_music sm
                JOIN suggested_music sm2
                  ON sm.event_id = sm2.event_id
                 AND sm.platformId = sm2.platformId
                 AND sm.id > sm2.id
            ");
        }
        
        // 2) Add unique constraint
        Schema::table('suggested_music', function (Blueprint $table) {
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
