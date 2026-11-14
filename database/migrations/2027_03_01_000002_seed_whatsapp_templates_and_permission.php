<?php

use App\Models\EventPlan;
use App\Support\AdminPermissions;
use App\Support\WhatsApp\TemplateCatalog;
use Database\Seeders\ModuleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The five templates x es/en as drafts (only what is missing: edits the admin made are never overwritten), the
 * `admin.whatsapp.manage` permission (superadmin) and the `whatsapp` module in the catalog (off, paid plans).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (TemplateCatalog::rows() as $row) {
            DB::table('whatsapp_templates')->insertOrIgnore($row + ['created_at' => now(), 'updated_at' => now()]);
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $permission = Permission::findOrCreate('admin.whatsapp.manage', 'web');
        Role::findOrCreate(AdminPermissions::SUPERADMIN, 'web')->givePermissionTo($permission);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // On a fresh database there are no plans yet: the seeder creates the module with the rest of the catalog.
        if (EventPlan::query()->exists()) {
            (new ModuleSeeder)->run();
        }
    }

    public function down(): void {}
};
