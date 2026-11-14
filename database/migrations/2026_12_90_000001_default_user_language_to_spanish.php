<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The product is Spanish first, so a preferences row created without a language speaks Spanish. Only the column
 * default changes: existing rows are not touched (nobody can tell a default 'en' from a chosen one without a review).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE user_preferences ALTER COLUMN language SET DEFAULT 'es'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE user_preferences ALTER COLUMN language SET DEFAULT 'en'");
    }
};
