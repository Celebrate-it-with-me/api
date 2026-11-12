<?php

namespace Database\Seeders;

use Database\Seeders\Demo\DemoSeeder;
use Illuminate\Database\Seeder;

/**
 * `php artisan migrate:fresh --seed` gives a ready-to-use local system.
 *
 * - Reference data (always): roles/permissions, event types, plans, budget
 *   categories and the admin user. All of them are idempotent.
 * - Demo data (local only): sample users and events. To load it in another
 *   non-production environment run `php artisan db:seed --class=Database\\Seeders\\Demo\\DemoSeeder`.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionSeeder::class,
            EventTypeSeeder::class,
            EventPlanSeeder::class,
            ModuleSeeder::class,
            InvitationTemplateSeeder::class,
            AdminUserSeeder::class,
        ]);

        if (app()->environment('local')) {
            $this->call(DemoSeeder::class);
        }
    }
}
