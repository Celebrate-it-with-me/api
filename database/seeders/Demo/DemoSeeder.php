<?php

namespace Database\Seeders\Demo;

use Illuminate\Database\Seeder;

/**
 * Demo data for local development (Spanish content, deterministic, no Faker).
 *
 * Needs the reference data from DatabaseSeeder (roles, event types, plans and
 * budget categories). Re-running it is safe: users and events are looked up by
 * email / URL slug and skipped when they already exist.
 *
 * Accounts (password for all of them: "password"):
 *   marisol@cwm.test  owner  of "Los XV de Valentina"   (complete event)
 *   carlos@cwm.test   editor of "Los XV de Valentina"
 *   lucia@cwm.test    viewer of "Los XV de Valentina"
 *   daniel@cwm.test   owner  of "Boda de Camila y Andrés" (partial) and
 *                             "Los XV de Sofía" (empty event)
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            DemoUserSeeder::class,
            DemoEventSeeder::class,
        ]);
    }
}
