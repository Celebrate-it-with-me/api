<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reference data: roles and permissions. Idempotent (safe to run many times).
 *
 * There are two kinds of roles in the same table:
 *  - Global roles: `admin` (admin panel) and `appUser`.
 *  - Event roles: `owner`, `editor`, `viewer`. They are attached to a user for a
 *    given event through the `event_user_roles` table, and the permissions of the
 *    role are what the frontend receives as `userPermissions` for that event
 *    (each frontend route declares a `requiredPermission`).
 */
class RolesAndPermissionSeeder extends Seeder
{
    /** Global permissions. */
    public const GLOBAL_PERMISSIONS = ['manage', 'app'];

    /** Permissions that can be granted on an event. */
    public const EVENT_PERMISSIONS = [
        // Events
        'view_events', 'view_event', 'create_event', 'edit_event', 'delete_event',
        // Guests and RSVP
        'view_guests', 'create_guest', 'edit_guest', 'delete_guest',
        'view_rsvp', 'manage_rsvp',
        // Menus
        'view_menus', 'view_menu', 'create_menu', 'edit_menu',
        'add_menu_item', 'edit_menu_item', 'delete_menu_item',
        // Locations
        'view_event_locations', 'create_event_locations', 'edit_event_locations',
        // Gallery / sweet memories
        'view_gallery', 'upload_photo', 'delete_photo', 'view_sweet_memories',
        // Music
        'view_suggested_music', 'add_song', 'delete_song', 'manage_playlist',
        // Other event sections
        'view_save_the_date', 'view_event_comments',
        // Collaboration
        'manage_collaborators',
        // Account pages (shown inside the event area)
        'view_profile', 'view_preferences', 'view_security', 'view_danger_zone',
    ];

    /** What an editor can do: everything except destructive/administrative actions. */
    private const EDITOR_EXCLUDED = ['delete_event', 'manage_collaborators', 'view_danger_zone'];

    /** What a viewer can do: read-only access plus their own account pages. */
    public const VIEWER_PERMISSIONS = [
        'view_events', 'view_event', 'view_guests', 'view_rsvp', 'view_menus', 'view_menu',
        'view_event_locations', 'view_gallery', 'view_sweet_memories', 'view_suggested_music',
        'view_save_the_date', 'view_event_comments',
        'view_profile', 'view_preferences', 'view_security',
    ];

    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach ([...self::GLOBAL_PERMISSIONS, ...self::EVENT_PERMISSIONS] as $name) {
            Permission::findOrCreate($name, 'web');
        }

        // Global roles
        Role::findOrCreate('admin', 'web')->syncPermissions(['manage']);
        Role::findOrCreate('appUser', 'web')->syncPermissions(['app']);

        // Event roles
        Role::findOrCreate('owner', 'web')
            ->syncPermissions([...self::EVENT_PERMISSIONS, 'app']);

        Role::findOrCreate('editor', 'web')
            ->syncPermissions(array_values(array_diff(self::EVENT_PERMISSIONS, self::EDITOR_EXCLUDED)));

        Role::findOrCreate('viewer', 'web')
            ->syncPermissions(self::VIEWER_PERMISSIONS);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->command?->info('Roles and permissions seeded.');
    }
}
