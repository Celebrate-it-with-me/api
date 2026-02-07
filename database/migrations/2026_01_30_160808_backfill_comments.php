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
        DB::table('event_comments')
            ->whereNull('authorable_type')
            ->update([
                'authorable_type' => DB::raw('created_by_class'),
                'authorable_id' => DB::raw('created_by_id'),
            ]);

        DB::table('event_comments')
            ->whereNull('status')
            ->orWhere('status', '=', '')
            ->update([
                'status' => DB::raw("CASE WHEN is_approved = 1 THEN 'visible' ELSE 'hidden' END"),
            ]);

        DB::table('event_comments')
            ->update([
                'status' => DB::raw("CASE WHEN is_approved = 1 THEN 'visible' ELSE 'hidden' END"),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
