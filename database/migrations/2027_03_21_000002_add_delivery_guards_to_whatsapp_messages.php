<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit W-01 / W-06 / W-09: a retry counter, the moment a send was handed to Meta (a message is only handed over once,
 * so a lost response can never produce a duplicate), and delivery statuses that arrive before we stored the message id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->unsignedSmallInteger('retry_count')->default(0);
            $table->timestamp('send_started_at')->nullable();
        });

        Schema::create('whatsapp_pending_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('wa_message_id', 120)->index();
            $table->string('status', 12);
            $table->json('payload');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_pending_statuses');
        Schema::table('whatsapp_messages', fn (Blueprint $table) => $table->dropColumn(['retry_count', 'send_started_at']));
    }
};
