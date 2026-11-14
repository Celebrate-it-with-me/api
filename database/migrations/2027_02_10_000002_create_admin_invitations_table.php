<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Pending invitations to join the admin team. The staff role is given when the person accepts, never before. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_invitations', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique(); // stored in lower case: one open invitation per address
            $table->string('name')->nullable();
            $table->string('role', 32);
            // Only the hash of the token is stored; the token travels in the link of the mail.
            $table->string('token_hash', 64)->index();
            $table->timestamp('expires_at');
            $table->timestamp('sent_at');
            $table->unsignedSmallInteger('resend_count')->default(0);
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_invitations');
    }
};
