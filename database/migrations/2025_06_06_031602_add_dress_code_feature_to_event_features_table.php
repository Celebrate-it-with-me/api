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
            $table->boolean('dress_code')->default(false)->after('rsvp')
                ->comment('Indicates if the event has a dress code feature enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('event_features', function (Blueprint $table) {
            $table->dropColumn('dress_code');
        });
    }
};
