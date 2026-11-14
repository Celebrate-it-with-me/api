<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-factor authentication of the admin staff (TOTP). It lives in its own table, not in the legacy
 * `users.two_factor_*` columns (plain text, used by an unfinished app feature): here the secret is encrypted at rest
 * and the recovery codes are stored only as keyed hashes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_two_factor', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->text('secret');
            $table->json('recovery_codes')->nullable();
            // Null while the user has scanned the QR but not yet proved it with a code: not active.
            $table->timestamp('confirmed_at')->nullable();
            // Time step of the last accepted TOTP code: the same code cannot be used twice.
            $table->unsignedBigInteger('last_used_step')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_two_factor');
    }
};
