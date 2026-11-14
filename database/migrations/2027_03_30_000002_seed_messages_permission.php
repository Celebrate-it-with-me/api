<?php

use App\Support\AdminPermissions;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** The `admin.messages.manage` permission (edit the text of transactional mails): superadmin only. */
return new class extends Migration
{
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $permission = Permission::findOrCreate('admin.messages.manage', 'web');
        Role::findOrCreate(AdminPermissions::SUPERADMIN, 'web')->givePermissionTo($permission);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void {}
};
