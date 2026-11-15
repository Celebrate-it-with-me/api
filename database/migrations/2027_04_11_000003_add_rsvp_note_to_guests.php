<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            // The guest's own message to the hosts (`notes` is the organizer's note about the guest).
            $table->text('rsvp_note')->nullable();
            $table->timestamp('rsvp_note_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('guests', function (Blueprint $table) {
            $table->dropColumn(['rsvp_note', 'rsvp_note_at']);
        });
    }
};
