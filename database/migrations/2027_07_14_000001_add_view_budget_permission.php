<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * `view_budget`: reading the budget and the padrinos (amounts). Owner and editor get it; the viewer does not
 * (owner's decision 2026-11-26). Idempotent: on a fresh database the seeder grants the same thing again.
 */
return new class extends Migration
{
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $permission = Permission::findOrCreate('view_budget', 'web');
        foreach (['owner', 'editor'] as $role) {
            Role::findOrCreate($role, 'web')->givePermissionTo($permission);
        }
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void {}
};
