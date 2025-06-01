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
            $table->boolean('location')
                ->default(false)
                ->after('menu')
                ->comment('Indicates if the event has a location feature');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('event_features', function (Blueprint $table) {
            $table->dropColumn('location');
        });
    }
};
