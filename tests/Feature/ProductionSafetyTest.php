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
        'services.whatsapp.driver' => 'fake',
    ]);

    expect(ProductionSafetyCheck::problems())->toHaveCount(7);
});

it('passes a proper production configuration', function () {
    config([
        'app.debug' => false, 'admin.require_two_factor_superadmin' => true, 'services.payments.gateway' => 'stripe', 'services.stripe.secret' => 'sk_live_x', 'services.stripe.webhook_secret' => 'whsec_x',
        'session.secure' => true, 'sanctum.stateful' => ['app.quinvite.com'],
        'services.whatsapp.driver' => 'meta', 'services.whatsapp.app_secret' => 's', 'services.whatsapp.verify_token' => 'v',
    ]);

    expect(ProductionSafetyCheck::problems())->toBe([]);
});

it('also flags the WhatsApp fake driver, Meta without secrets and an empty stateful list (W-10)', function () {
    $base = ['app.debug' => false, 'admin.require_two_factor_superadmin' => true, 'services.payments.gateway' => 'stripe', 'services.stripe.secret' => 'rk_live_x', 'services.stripe.webhook_secret' => 'x', 'session.secure' => true];

    config($base + ['sanctum.stateful' => ['app.quinvite.com'], 'services.whatsapp.driver' => 'fake']);
    expect(ProductionSafetyCheck::problems())->toContain('WHATSAPP_DRIVER must not be "fake".');

    config($base + ['sanctum.stateful' => ['app.quinvite.com'], 'services.whatsapp.driver' => 'meta', 'services.whatsapp.app_secret' => null, 'services.whatsapp.verify_token' => '']);
    expect(ProductionSafetyCheck::problems())->toContain('WHATSAPP_APP_SECRET must be set.', 'WHATSAPP_VERIFY_TOKEN must be set.');

    config($base + ['sanctum.stateful' => [''], 'services.whatsapp.driver' => 'meta', 'services.whatsapp.app_secret' => 's', 'services.whatsapp.verify_token' => 'v']);
    expect(ProductionSafetyCheck::problems())->toBe(['SANCTUM_STATEFUL_DOMAINS must list only the real SPA domains.']);
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

it('requires superadmin two-factor in production and defaults it on there (audit A-3)', function () {
    config(['admin.require_two_factor_superadmin' => false]);
    expect(ProductionSafetyCheck::problems())->toContain('ADMIN_REQUIRE_2FA_SUPERADMIN must be true.');

    config(['admin.require_two_factor_superadmin' => true]);
    expect(ProductionSafetyCheck::problems())->not->toContain('ADMIN_REQUIRE_2FA_SUPERADMIN must be true.');
});

it('defaults ADMIN_REQUIRE_2FA_SUPERADMIN to true only when APP_ENV is production', function () {
    $keys = ['APP_ENV', 'ADMIN_REQUIRE_2FA_SUPERADMIN'];
    $saved = array_map(fn ($k) => $_SERVER[$k] ?? null, array_combine($keys, $keys));
    $load = function (?string $appEnv, ?string $flag) use ($keys): bool {
        foreach (array_combine($keys, [$appEnv, $flag]) as $k => $v) {
            if ($v === null) {
                unset($_SERVER[$k], $_ENV[$k]);
            } else {
                $_SERVER[$k] = $_ENV[$k] = $v;
            }
        }

        return (bool) (require base_path('config/admin.php'))['require_two_factor_superadmin'];
    };

    try {
        expect($load('production', null))->toBeTrue()
            ->and($load('local', null))->toBeFalse()
            ->and($load('production', 'false'))->toBeFalse()
            ->and($load('local', 'true'))->toBeTrue();
    } finally {
        foreach ($saved as $k => $v) {
            if ($v === null) {
                unset($_SERVER[$k], $_ENV[$k]);
            } else {
                $_SERVER[$k] = $_ENV[$k] = $v;
            }
        }
    }
});
