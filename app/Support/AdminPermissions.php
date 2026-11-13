<?php

namespace App\Support;

/**
 * The admin panel's roles and what each can do (docs/plans/admin-brief.md, section 7).
 * One list, used by the migration, the seeder, the middleware and the tests.
 */
final class AdminPermissions
{
    public const SUPERADMIN = 'superadmin';

    public const SUPPORT = 'support';

    /** Roles that can open the admin panel. */
    public const STAFF_ROLES = [self::SUPERADMIN, self::SUPPORT];

    /** What support can do: look, moderate, gift a function to one event, impersonate. */
    public const SUPPORT_PERMISSIONS = [
        'admin.dashboard.view',
        'admin.events.view',
        'admin.users.view',
        'admin.content.moderate',
        'admin.modules.gift',
        'admin.impersonate',
        'admin.support.manage',
        'admin.users.reset_password',
    ];

    /** What only a superadmin can do. */
    public const SUPERADMIN_ONLY = [
        'admin.events.change_plan',
        'admin.events.manage',
        'admin.purchases.manage',
        'admin.refunds',
        'admin.pricing.manage',
        'admin.catalog.manage',
        'admin.templates.manage',
        'admin.users.manage',
        'admin.users.delete',
        'admin.users.suspend',
        'admin.users.export',
        'admin.team.manage',
        'admin.audit.view',
        'admin.legacy',
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return [...self::SUPPORT_PERMISSIONS, ...self::SUPERADMIN_ONLY];
    }

    /** @return list<string> */
    public static function for(string $role): array
    {
        return $role === self::SUPERADMIN ? self::all() : self::SUPPORT_PERMISSIONS;
    }
}
