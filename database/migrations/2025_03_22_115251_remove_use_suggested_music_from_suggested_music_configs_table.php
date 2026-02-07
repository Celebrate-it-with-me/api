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
        Schema::table('suggested_music_configs', function (Blueprint $table) {
            $table->dropColumn('use_suggested_music');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('suggested_music_configs', function (Blueprint $table) {
            $table->tinyInteger('use_suggested_music')->default(0)->after('secondary_color');
        });
    }
};
