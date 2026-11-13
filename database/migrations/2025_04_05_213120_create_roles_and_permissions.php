<?php
    
    use App\Models\User;
    use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
    use Spatie\Permission\Models\Permission;
    use Spatie\Permission\Models\Role;
    use Spatie\Permission\PermissionRegistrar;
    
    return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Clear permission cache
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        
        // Create permissions
        $permissions = [
            'manage',
            'app'
        ];
        
        foreach ($permissions as $permission) {
            Permission::create([
                'name' => $permission,
            ]);
        }
        
        // Create roles and assign permissions
        $adminRole = Role::create(['name' => 'admin']);
        $adminRole->givePermissionTo(['manage']);
        
        $appUserRole = Role::create(['name' => 'appUser']);
        $appUserRole->givePermissionTo(['app']);
        
        // The admin user is created by AdminUserSeeder, with credentials from the environment: a migration must
        // never create an account with a known password.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove roles and permissions
        Role::where('name', 'admin')->delete();
        Role::where('name', 'appUser')->delete();
        
        Permission::where('name', 'manage')->delete();
        Permission::where('name', 'app')->delete();
        
        // Clear permission cache
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        
    }
};
