<?php

use Database\Seeders\AssistantTaskSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * A deployed database gets the assistant's starting catalog without anybody running seeders by hand. It only creates
 * the tasks that are missing; what the admin edited stays (same rule as the seeder).
 */
return new class extends Migration
{
    public function up(): void
    {
        (new AssistantTaskSeeder)->run();
    }

    public function down(): void
    {
        // Data only: nothing to undo.
    }
};
