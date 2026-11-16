<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Postgres does not index foreign keys by itself. These are the columns the app filters or joins on all the time
 * (every page of the roster, the dashboard, the invitation, the seating screen) that had no index of their own:
 * the plan was a sequential scan that grows with every event in the system, not with the one being read
 * (docs/plans/performance-baseline-2026-11-17.md, section 4).
 *
 * Plain btree indexes, named by Laravel's convention (`{table}_{columns}_index`). Each one is created only if the table,
 * the columns and an index on exactly those columns are not there yet, so the migration can run on a database that
 * somebody already patched by hand.
 */
return new class extends Migration
{
    /** @var array<string, list<list<string>>> table => the column lists to index */
    private const INDEXES = [
        'guests' => [['parent_id']],
        'event_activities' => [['event_id', 'created_at']],
        'timelines' => [['event_id']],
        'rsvps' => [['event_id']],
        'tables' => [['event_id']],
        'table_assignments' => [['table_id'], ['guest_id']],
        'sweet_memories_images' => [['event_id']],
        'sweet_memories_config' => [['event_id']],
        'suggested_music_configs' => [['event_id']],
        'event_config_comment' => [['event_id']],
        'background_music' => [['event_id']],
        'padrinos' => [['event_id']],
        'event_locations' => [['event_id']],
        'event_location_images' => [['event_location_id']],
        'menus' => [['event_id']],
        'menu_items' => [['menu_id']],
        'guest_menu' => [['guest_id'], ['menu_item_id']],
        'budget_categories' => [['event_budget_id']],
        'budget_items' => [['event_budget_id']],
        'guest_companions' => [['main_guest_id']],
        'guest_rsvp_logs' => [['guest_id']],
        'guest_invitations' => [['guest_id']],
        'event_user_roles' => [['user_id']],
        'event_purchases' => [['user_id']],
        'invoices' => [['event_id']],
        'event_collaboration_invites' => [['event_id']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $lists) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($lists as $columns) {
                if (! Schema::hasColumns($table, $columns) || Schema::hasIndex($table, $columns)) {
                    continue;
                }

                Schema::table($table, fn (Blueprint $t) => $t->index($columns, $this->name($table, $columns)));
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $lists) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($lists as $columns) {
                $name = $this->name($table, $columns);

                if (Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
                }
            }
        }
    }

    /** @param list<string> $columns */
    private function name(string $table, array $columns): string
    {
        return $table . '_' . implode('_', $columns) . '_index';
    }
};
