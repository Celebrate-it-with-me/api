<?php

use App\Support\AdminPermissions;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** `admin.system.health` (see the health screen) and `admin.system.manage` (retry/delete failed jobs, maintenance mode): superadmin only. */
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

    public function down(): void {}
};
