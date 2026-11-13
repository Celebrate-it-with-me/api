<?php

use App\Support\AdminPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 80);
            $table->string('subject_type', 40)->nullable();   // an alias: event, user, purchase, coupon, plan, team
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->unsignedBigInteger('event_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable(); // the account the action was about
            $table->string('reason', 500)->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->foreignId('impersonated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('actor_id');
            $table->index('action');
            $table->index('event_id');
            $table->index('user_id');
            $table->index('created_at');
        });

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (AdminPermissions::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }
        foreach (AdminPermissions::STAFF_ROLES as $role) {
            Role::findOrCreate($role, 'web')->syncPermissions(AdminPermissions::for($role));
        }

        // The single `admin` role becomes `superadmin`: the same people, the same access, now with a name for the rest.
        $old = Role::query()->where('name', 'admin')->where('guard_name', 'web')->first();
        if ($old) {
            $superadmin = Role::findByName(AdminPermissions::SUPERADMIN, 'web');
            $ids = DB::table('model_has_roles')->where('role_id', $old->id)->pluck('model_id');
            foreach ($ids as $id) {
                DB::table('model_has_roles')->insertOrIgnore(['role_id' => $superadmin->id, 'model_type' => 'user', 'model_id' => $id]);
            }
            $old->delete();
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $admin = Role::findOrCreate('admin', 'web');
        $superadmin = Role::query()->where('name', AdminPermissions::SUPERADMIN)->first();
        if ($superadmin) {
            $ids = DB::table('model_has_roles')->where('role_id', $superadmin->id)->pluck('model_id');
            foreach ($ids as $id) {
                DB::table('model_has_roles')->insertOrIgnore(['role_id' => $admin->id, 'model_type' => 'user', 'model_id' => $id]);
            }
        }
        foreach (AdminPermissions::STAFF_ROLES as $role) {
            Role::query()->where('name', $role)->first()?->delete();
        }
        Permission::query()->whereIn('name', AdminPermissions::all())->delete();

        Schema::dropIfExists('admin_audit_log');
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
