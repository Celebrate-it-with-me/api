<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Event life cycle: draft / published / archived.
 *
 * - `canceled` becomes `archived`; `visibility` goes away (access is by link + guest code).
 * - `published_at` remembers the first publication: the public address is locked from then on,
 *   even if the event goes back to draft, because its links may already be in guests' WhatsApp.
 * - The end date and the description become optional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->timestamp('published_at')->nullable()->after('status');
        });

        DB::table('events')->where('status', 'canceled')->update(['status' => 'archived']);
        DB::table('events')->where('status', 'published')->update(['published_at' => DB::raw('updated_at')]);

        if (DB::getDriverName() === 'pgsql') {
            // The old enum was a varchar plus a CHECK that still lists `canceled`.
            DB::statement('ALTER TABLE events DROP CONSTRAINT IF EXISTS events_status_check');
        }

        Schema::table('events', function (Blueprint $table) {
            $table->string('status', 20)->default('draft')->change();
            $table->timestamp('end_date')->nullable()->change();
            $table->text('event_description')->nullable()->change();
            $table->dropColumn('visibility');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE events ADD CONSTRAINT events_status_check CHECK (status IN ('draft', 'published', 'archived'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE events DROP CONSTRAINT IF EXISTS events_status_check');
        }

        DB::table('events')->whereNull('end_date')->update(['end_date' => DB::raw('start_date')]);
        DB::table('events')->whereNull('event_description')->update(['event_description' => '']);

        Schema::table('events', function (Blueprint $table) {
            $table->string('visibility')->default('private');
            $table->timestamp('end_date')->nullable(false)->change();
            $table->string('event_description')->nullable(false)->change();
            $table->dropColumn('published_at');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE events ADD CONSTRAINT events_status_check CHECK (status IN ('draft', 'published', 'archived', 'canceled'))");
            DB::statement("ALTER TABLE events ADD CONSTRAINT events_visibility_check CHECK (visibility IN ('public', 'private', 'restricted'))");
        }
    }
};
