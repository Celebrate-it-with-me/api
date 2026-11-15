<?php

use App\Models\EventPlan;
use Database\Seeders\ModuleSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * The `background_music` module (control key for the invitation's music section and for uploading, replacing and
 * deleting the song) in the catalog: on, premium. The seeder only creates what is missing, so running this twice, or
 * after the admin changed the module (switch, order, plans), changes nothing. On a fresh database there are no plans
 * yet: the seeder runs with the rest.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (EventPlan::query()->exists()) {
            (new ModuleSeeder)->run();
        }
    }

    public function down(): void {}
};
