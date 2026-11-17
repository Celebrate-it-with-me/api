<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Email verification starts now: every account that exists when this runs (demo, admin, anyone who signed up before
 * this change, and anyone who came in through a social provider, which already verified the address) counts as
 * verified. Only rows still null are touched, so running it again changes nothing. `users.email_verified_at` itself
 * has been there since the first migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->whereNull('email_verified_at')->update(['email_verified_at' => now()]);
    }

    public function down(): void
    {
        // Not reversible: it is not known which accounts were unverified before.
    }
};
