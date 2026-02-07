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
        Schema::create('guest_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guest_id')->constrained('guests');
            $table->string('channel'); // email, sms, whatsapp
            $table->timestamp('sent_at');
            $table->string('status'); // sent, opened, clicked, bounced
            $table->text('message_preview')->nullable();
            $table->json('response_payload')->nullable();
            $table->string('attempted_by')->nullable(); // Who sent the invitation admin, system
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('guest_invitations');
    }
};
