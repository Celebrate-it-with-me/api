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
        Schema::table('suggested_music_votes', function (Blueprint $table) {
            // Drop old constraint
            $table->dropForeign(['main_guest_id']);

            // Rename column
            $table->renameColumn('main_guest_id', 'guest_id');

            // Add new constraint to guests table
            $table->foreign('guest_id')
                ->references('id')
                ->on('guests')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('suggested_music_votes', function (Blueprint $table) {
            $table->dropForeign(['guest_id']);
            $table->renameColumn('guest_id', 'main_guest_id');
            $table->foreign('main_guest_id')
                ->references('id')
                ->on('main_guests')
                ->onDelete('cascade');
        });
    }
};
