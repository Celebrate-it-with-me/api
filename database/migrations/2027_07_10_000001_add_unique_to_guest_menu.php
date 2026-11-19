<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A guest picks a dish once: `guest_menu` gains unique(guest_id, menu_item_id) so saving the RSVP twice (or two tabs at
 * once) can never duplicate a choice. Duplicates that already exist are removed first, keeping the oldest row of each
 * pair. Portable SQL only (it runs from scratch on PostgreSQL); running it twice changes nothing.
 */
return new class extends Migration
{
    private const INDEX = 'guest_menu_guest_id_menu_item_id_unique';

    public function up(): void
    {
        DB::table('guest_menu')
            ->whereNotIn('id', DB::table('guest_menu')->selectRaw('MIN(id)')->groupBy('guest_id', 'menu_item_id'))
            ->delete();

        if ($this->hasIndex()) {
            return;
        }

        Schema::table('guest_menu', function (Blueprint $table) {
            $table->unique(['guest_id', 'menu_item_id'], self::INDEX);
        });
    }

    public function down(): void
    {
        if (! $this->hasIndex()) {
            return;
        }

        Schema::table('guest_menu', function (Blueprint $table) {
            $table->dropUnique(self::INDEX);
        });
    }

    private function hasIndex(): bool
    {
        return in_array(self::INDEX, array_column(Schema::getIndexes('guest_menu'), 'name'), true);
    }
};
