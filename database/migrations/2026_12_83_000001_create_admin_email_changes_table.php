<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A staff member's request to change her sign-in email: it takes effect only when the NEW address confirms by link. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_email_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('new_email');
            // Only the hash of the token is stored; the token travels in the link sent to the new address.
            $table->string('token_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_email_changes');
    }
};
