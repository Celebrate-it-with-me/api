<?php

namespace Database\Seeders;

use App\Models\User;
use Hash;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Creating Permissions
        $permissions = [
            'manage',
            'app',
        ];

        foreach ($permissions as $permission) {
            Permission::create([
                'name' => $permission,
            ]);
        }

        $adminRole = Role::create(['name' => 'admin']);
        $adminRole->givePermissionTo(['manage']);

        $appUserRole = Role::create(['name' => 'appUser']);
        $appUserRole->givePermissionTo(['app']);

        $user = User::query()->firstOrCreate(
            [
                'email' => 'henrycarmenateg@gmail.com',
            ],
            [
                'name' => 'Henry Carmenate',
                'password' => Hash::make('password'),
            ]
        );

        $user->assignRole('admin');

        $this->command->info('Roles and Permissions seeded successfully!');
    }
}
