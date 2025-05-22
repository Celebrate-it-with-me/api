<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class EventUserRoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        
        $permissions = [
            // Event
            'view_event', 'edit_event', 'delete_event',
            
            // Guest
            'add_guest', 'edit_guest', 'delete_guest', 'view_guest_list',
            
            // Menu
            'add_menu', 'edit_menu', 'delete_menu', 'view_menu',
            
            // Menu Items
            'add_menu_item', 'edit_menu_item', 'delete_menu_item',
            
            // Photos
            'upload_photo', 'delete_photo', 'view_gallery',
            
            // Music
            'add_song', 'delete_song', 'manage_playlist',
            
            // Location
            'edit_location', 'add_location_photo',
            
            // RSVP
            'manage_rsvp', 'view_rsvp',
            
            // Collaboration
            'manage_collaborators'
        ];
        
        foreach ($permissions as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission]);
        }
        
        $owner = Role::query()->firstOrCreate(['name' => 'owner']);
        $editor = Role::query()->firstOrCreate(['name' => 'editor']);
        $viewer = Role::query()->firstOrCreate(['name' => 'viewer']);
        
        $owner->syncPermissions(Permission::all());
        
        $editor->syncPermissions([
            'view_event', 'edit_event',
            
            'add_guest', 'edit_guest', 'delete_guest', 'view_guest_list',
            
            'add_menu', 'edit_menu', 'delete_menu', 'view_menu',
            'add_menu_item', 'edit_menu_item', 'delete_menu_item',
            
            'upload_photo', 'delete_photo', 'view_gallery',
            
            'add_song', 'delete_song', 'manage_playlist',
            
            'edit_location', 'add_location_photo',
            
            'manage_rsvp', 'view_rsvp'
        ]);
        
        $viewer->syncPermissions([
            'view_event',
            'view_guest_list',
            'view_menu',
            'view_gallery',
            'view_rsvp'
        ]);
        
    }
}
