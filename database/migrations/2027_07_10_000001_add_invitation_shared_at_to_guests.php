<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the organizer marked a family's invitation as shared by hand (the manual `wa.me` send). Null until then.
 * Not proof of delivery: it only says "the organizer opened WhatsApp for this family". Existing rows stay null.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('guests', 'invitation_shared_at')) {
            return;
        }

        Schema::table('guests', function (Blueprint $table) {
            $table->timestamp('invitation_shared_at')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('guests', 'invitation_shared_at')) {
            return;
        }

        Schema::table('guests', function (Blueprint $table) {
            $table->dropColumn('invitation_shared_at');
        });
    }
};
