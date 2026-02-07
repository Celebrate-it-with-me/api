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
        Schema::table('event_features', function (Blueprint $table) {
            $table->tinyInteger('event_comments')->default(0)->after('background_music');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('event_features', function (Blueprint $table) {
            $table->dropColumn('event_comments');
        });
    }
};
