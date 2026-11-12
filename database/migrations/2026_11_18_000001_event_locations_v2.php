<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_locations', function (Blueprint $table) {
            // ceremony | reception | other. The time comes from the program of the day, not from here.
            $table->string('type', 12)->default('other')->after('event_id');
            $table->string('place_id')->nullable()->after('longitude');
        });

        // An address picked by hand may have no zip code or coordinates.
        Schema::table('event_locations', function (Blueprint $table) {
            $table->string('zip_code')->nullable()->change();
            $table->string('state')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('event_locations', function (Blueprint $table) {
            $table->dropColumn(['type', 'place_id']);
        });
    }
};
