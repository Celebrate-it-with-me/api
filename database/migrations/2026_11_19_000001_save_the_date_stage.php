<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // What the public link shows while the event is published: the Save the date or the full invitation.
            $table->string('invitation_stage', 20)->default('invitation')->after('status');
        });

        Schema::table('save_the_dates', function (Blueprint $table) {
            $table->text('message')->nullable()->after('use_add_to_calendar');
        });
    }

    public function down(): void
    {
        Schema::table('save_the_dates', fn (Blueprint $table) => $table->dropColumn('message'));
        Schema::table('events', fn (Blueprint $table) => $table->dropColumn('invitation_stage'));
    }
};
