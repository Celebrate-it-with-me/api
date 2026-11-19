<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data clean-up for the seating module, safe to run again:
 *  1. Seats still held by guests that were soft-deleted (the foreign key cascade never fired): removed.
 *  2. `seat_number` used to hold a label ("Mesa 1 - Seat 3"); it now holds just the number ("3"). Labels are
 *     converted to the trailing number; rows with no number become ''. Rows already numeric are not touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('table_assignments')
            ->whereIn('guest_id', DB::table('guests')->whereNotNull('deleted_at')->select('id'))
            ->delete();

        DB::table('table_assignments')->select(['id', 'seat_number'])->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                $seat = (string) $row->seat_number;
                if (preg_match('/^\d+$/', $seat) === 1) {
                    continue;
                }

                $number = preg_match('/(\d+)\s*$/', $seat, $m) === 1 ? $m[1] : '';
                DB::table('table_assignments')->where('id', $row->id)->update(['seat_number' => $number]);
            }
        });
    }

    public function down(): void
    {
        // The removed ghosts and the old labels are not worth restoring.
    }
};
