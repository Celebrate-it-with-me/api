<?php

use App\Models\User;
use App\Support\ProductionSafetyCheck;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\RolesAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('flags every development setting that is unsafe in production (S-16)', function () {
    config([
        'app.debug' => true, 'services.payments.gateway' => 'fake', 'services.payments.webhook_secret' => null,
        'session.secure' => null, 'sanctum.stateful' => ['dev.app.cwm.com', 'localhost'],
    ]);

    expect(ProductionSafetyCheck::problems())->toHaveCount(5);
});

it('passes a proper production configuration', function () {
    config([
        'app.debug' => false, 'services.payments.gateway' => 'stripe', 'services.payments.webhook_secret' => 'whsec_x',
        'session.secure' => true, 'sanctum.stateful' => ['app.quinvite.com'],
    ]);

    expect(ProductionSafetyCheck::problems())->toBe([]);
});

it('does not create the well-known admin account outside local and testing', function () {
    $this->seed(RolesAndPermissionSeeder::class);
    config(['app.admin_user.email' => null, 'app.admin_user.password' => null]);

    $this->app['env'] = 'staging';
    $this->seed(AdminUserSeeder::class);
    expect(User::where('email', AdminUserSeeder::LOCAL_EMAIL)->exists())->toBeFalse();

    $this->app['env'] = 'local';
    $this->seed(AdminUserSeeder::class);
    expect(User::where('email', AdminUserSeeder::LOCAL_EMAIL)->exists())->toBeTrue();
});

it('keeps the dev domains out of the production stateful list by default', function () {
    expect(config('sanctum.stateful'))->not->toContain('dev.admin.cwm.com');
});
