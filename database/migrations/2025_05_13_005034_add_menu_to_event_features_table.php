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
            $table->boolean('menu')
                ->default(false)
                ->after('sweet_memories')
                ->comment('Indicates if the event has a menu feature');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('event_features', function (Blueprint $table) {
            $table->dropColumn('menu');
        });
    }
};
