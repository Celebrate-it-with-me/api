<?php

use App\Models\User;
use App\Support\AdminPermissions;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function removeLegacyMigration(): object
{
    return require database_path('migrations/2026_12_50_000001_remove_admin_legacy_permission.php');
}

it('no longer seeds or lists admin.legacy', function () {
    $this->seed(DatabaseSeeder::class);

    expect(AdminPermissions::all())->not->toContain('admin.legacy')
        ->and(Permission::where('name', 'admin.legacy')->exists())->toBeFalse();
});

it('drops an existing admin.legacy permission and its assignments, and can be reverted', function () {
    $this->seed(DatabaseSeeder::class);
    $permission = Permission::create(['name' => 'admin.legacy', 'guard_name' => 'web']);
    Role::findByName(AdminPermissions::SUPERADMIN, 'web')->givePermissionTo($permission);
    $user = User::factory()->create();
    $user->givePermissionTo($permission);
    $others = Permission::count() - 1;

    $migration = removeLegacyMigration();
    $migration->up();

    expect(Permission::where('name', 'admin.legacy')->exists())->toBeFalse()
        ->and(Permission::count())->toBe($others)
        ->and(Role::findByName(AdminPermissions::SUPERADMIN, 'web')->permissions()->where('name', 'admin.legacy')->exists())->toBeFalse()
        ->and($user->fresh()->permissions)->toBeEmpty();

    $migration->up(); // idempotent when it is already gone
    $migration->down();
    expect(Permission::where('name', 'admin.legacy')->count())->toBe(1);
});
