<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Databases that already had the plans: the full one gets its list price (the admin can change it) and its 300 guests. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('event_plans')->where('slug', 'premium')->where('price_cents', 0)->update(['price_cents' => 9900, 'max_guests' => 300]);
    }

    public function down(): void {}
};
