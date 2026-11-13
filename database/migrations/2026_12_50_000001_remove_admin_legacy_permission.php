<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * `admin.legacy` no longer protects any route (the legacy endpoints were removed). Drops the permission and its
 * assignments to roles and users when it exists. Reversible: `down()` recreates the bare permission.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tables = config('permission.table_names');
        $ids = DB::table($tables['permissions'])->where('name', 'admin.legacy')->pluck('id');

        if ($ids->isNotEmpty()) {
            DB::table($tables['role_has_permissions'])->whereIn('permission_id', $ids)->delete();
            DB::table($tables['model_has_permissions'])->whereIn('permission_id', $ids)->delete();
            DB::table($tables['permissions'])->whereIn('id', $ids)->delete();
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        $tables = config('permission.table_names');

        if (! DB::table($tables['permissions'])->where('name', 'admin.legacy')->exists()) {
            DB::table($tables['permissions'])->insert([
                'name' => 'admin.legacy', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
