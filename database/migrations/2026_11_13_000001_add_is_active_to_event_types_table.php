<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Only quinceañera and wedding are offered for now. The other types stay in
     * the table (existing events keep working) but are hidden from new events.
     */
    public function up(): void
    {
        Schema::table('event_types', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('icon');
        });

        DB::table('event_types')
            ->whereNotIn('slug', ['quinceanera', 'wedding'])
            ->update(['is_active' => false]);
    }

    public function down(): void
    {
        Schema::table('event_types', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
