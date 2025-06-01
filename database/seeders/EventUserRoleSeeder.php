<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class EventUserRoleSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        DB::table('role_has_permissions')->truncate();
        DB::table('model_has_roles')->truncate();
        DB::table('model_has_permissions')->truncate();
        DB::table('permissions')->truncate();
        DB::table('roles')->truncate();

        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $permissions = [
            // Global/Admin
            'manage',

            // Event
            'view_events', 'view_event', 'create_event', 'edit_event', 'delete_event',

            // Guest
            'view_guests', 'create_guest', 'delete_guest', 'edit_guest',

            // Menu
            'view_menus', 'create_menu', 'edit_menu', 'view_menu',

            // Menu Items
            'add_menu_item', 'edit_menu_item', 'delete_menu_item',

            // Photos
            'upload_photo', 'delete_photo', 'view_gallery',

            // Music
            'add_song', 'delete_song', 'manage_playlist',

            // Location
            'view_event_locations', 'create_event_locations', 'edit_event_locations',

            // RSVP
            'manage_rsvp', 'view_rsvp',

            // Collaboration
            'manage_collaborators',

        ];

        foreach ($permissions as $permission) {
            Permission::create(['name' => $permission]);
        }

        // ✅ Roles base
        $owner = Role::create(['name' => 'owner']);
        $editor = Role::create(['name' => 'editor']);
        $viewer = Role::create(['name' => 'viewer']);

        $owner->syncPermissions(Permission::all());

        $editor->syncPermissions([
            'view_events', 'view_event', 'create_event', 'edit_event', 'delete_event',
            'view_guests', 'create_guest', 'delete_guest', 'edit_guest',
            'view_menus', 'create_menu', 'edit_menu', 'view_menu',
            'add_menu_item', 'edit_menu_item', 'delete_menu_item',
            'upload_photo', 'delete_photo', 'view_gallery',
            'add_song', 'delete_song', 'manage_playlist',
            'view_event_locations', 'create_event_locations', 'edit_event_locations',
            'manage_rsvp', 'view_rsvp', 'manage',
        ]);

        $viewer->syncPermissions([
            'view_event',
            'view_guests',
            'view_menus',
            'view_gallery',
            'view_rsvp',
        ]);

        $admin = Role::create(['name' => 'admin']);
        $admin->givePermissionTo(['manage']);

        $user = User::firstOrCreate(
            ['email' => 'henrycarmenateg@gmail.com'],
            [
                'name' => 'Henry Carmenate',
                'password' => Hash::make('password'),
            ]
        );

        $user->assignRole('admin');

        $this->command->info('✅ Roles and permissions seeded successfully!');
    }
}
