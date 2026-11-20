<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PostgreSQL compares emails with case, so "Ana@x.com" and "ana@x.com" could both register. The app now stores and
 * looks up lowercase; this adds a unique index on lower(email) as the last line of defence.
 *
 * It never deletes anything: if accounts that differ only by case already exist, the index is NOT created and the
 * addresses are written to the log so the owner can decide (merge, rename) and re-run the migration. Running it twice
 * changes nothing.
 */
return new class extends Migration
{
    private const INDEX = 'users_email_lower_unique';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $duplicates = DB::table('users')
            ->selectRaw('lower(email) as email, count(*) as total')
            ->groupByRaw('lower(email)')
            ->havingRaw('count(*) > 1')
            ->pluck('total', 'email');

        if ($duplicates->isNotEmpty()) {
            Log::warning('users_email_lower_unique skipped: emails that differ only by case exist.', ['emails' => $duplicates->all()]);

            return;
        }

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS ' . self::INDEX . ' ON users (lower(email))');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS ' . self::INDEX);
        }
    }
};
