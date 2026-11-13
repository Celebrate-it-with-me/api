<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff moderation (admin A6): a photo or comment hidden by staff is marked with when and by whom.
 * Comments keep using `status`; `hidden_at` tells "hidden by staff" apart from "hidden by the organizer".
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['sweet_memories_images', 'event_comments'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->timestamp('hidden_at')->nullable();
                $t->unsignedBigInteger('hidden_by')->nullable();
                $t->index('hidden_at');
            });
        }
    }

    public function down(): void
    {
        foreach (['sweet_memories_images', 'event_comments'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropIndex($table . '_hidden_at_index');
                $t->dropColumn(['hidden_at', 'hidden_by']);
            });
        }
    }
};
