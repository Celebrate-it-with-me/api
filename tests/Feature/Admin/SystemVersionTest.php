<?php

use App\Models\User;
use App\Support\AdminPermissions;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const VERSION_URL = '/api/v1/admin/system/version';

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    User::role(AdminPermissions::STAFF_ROLES)->get()->each(fn (User $u) => $u->syncRoles([]));
});

function versionStaff(string $role): User
{
    $u = User::factory()->create();
    $u->assignRole($role);

    return $u;
}

it('returns the version payload to staff with the system health permission', function () {
    config(['version.commit' => 'abc1234', 'version.built_at' => '2026-12-01T15:04:05Z']);

    $res = $this->actingAs(versionStaff(AdminPermissions::SUPERADMIN))->getJson(VERSION_URL)->assertOk();

    expect(array_keys($res->json()))->toEqualCanonicalizing(['version', 'commit', 'builtAt', 'environment', 'php', 'laravel']);
    $res->assertJsonPath('version', trim(file_get_contents(base_path('VERSION'))))
        ->assertJsonPath('commit', 'abc1234')
        ->assertJsonPath('builtAt', '2026-12-01T15:04:05Z')
        ->assertJsonPath('environment', app()->environment())
        ->assertJsonPath('php', PHP_VERSION)
        ->assertJsonPath('laravel', app()->version());
});

it('returns null commit and builtAt when the deploy does not set them', function () {
    config(['version.commit' => null, 'version.built_at' => null]);

    $this->actingAs(versionStaff(AdminPermissions::SUPERADMIN))->getJson(VERSION_URL)
        ->assertOk()->assertJsonPath('commit', null)->assertJsonPath('builtAt', null);
});

it('guards the version endpoint', function () {
    $this->getJson(VERSION_URL)->assertUnauthorized();
    $this->actingAs(User::factory()->create())->getJson(VERSION_URL)->assertForbidden();
    $this->actingAs(versionStaff(AdminPermissions::SUPPORT))->getJson(VERSION_URL)->assertForbidden();
});
