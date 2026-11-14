<?php

use App\Support\AdminPermissions;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** `admin.security.manage` (turn the "require two-factor for all staff" switch on or off): superadmin only. */
return new class extends Migration
{
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permission = Permission::findOrCreate('admin.security.manage', 'web');
        Role::findOrCreate(AdminPermissions::SUPERADMIN, 'web')->givePermissionTo($permission);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void {}
};
