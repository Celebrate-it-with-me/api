<?php

use App\Support\AdminPermissions;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** The permissions the Operation modules (support inbox, user suspension and export) added to the staff roles. */
return new class extends Migration
{
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (AdminPermissions::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }
        foreach (AdminPermissions::STAFF_ROLES as $role) {
            Role::findOrCreate($role, 'web')->syncPermissions(AdminPermissions::for($role));
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Left in place: removing a permission someone already relies on is worse than keeping an unused one.
    }
};
