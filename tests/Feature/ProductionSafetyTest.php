<?php

use App\Http\Services\Payments\FakeGateway;
use App\Http\Services\Payments\PurchaseService;
use App\Http\Services\WhatsApp\WhatsAppService;
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

it('flags a WhatsApp driver that is not literally meta, because the service would run as fake (P-3)', function () {
    $base = ['app.debug' => false, 'admin.require_two_factor_superadmin' => true, 'services.payments.gateway' => 'stripe', 'services.stripe.secret' => 'rk_live_x', 'services.stripe.webhook_secret' => 'x', 'session.secure' => true,
        'sanctum.stateful' => ['app.quinvite.com'], 'services.whatsapp.app_secret' => 's', 'services.whatsapp.verify_token' => 'v'];

    foreach (['twilio', '', null, 'metaa'] as $driver) {
        config($base + ['services.whatsapp.driver' => $driver]);
        expect(ProductionSafetyCheck::problems())->toContain('WHATSAPP_DRIVER must be "meta" (any other value runs as the fake driver).');
    }

    // "Meta " is read the same way by the service and by the check: it runs as meta and passes.
    config($base + ['services.whatsapp.driver' => ' Meta ']);
    expect(app(WhatsAppService::class)->driverName())->toBe('meta')
        ->and(ProductionSafetyCheck::problems())->toBe([]);
});

it('keeps the strict gateway names: only stripe or manual pass in production (P-3)', function () {
    foreach (['Stripe', ' stripe', '', null, 'paddle'] as $gateway) {
        config(['services.payments.gateway' => $gateway]);
        expect(ProductionSafetyCheck::problems())->toContain('PAYMENTS_GATEWAY must be "stripe" (or "manual").');
    }
});

it('warns when APP_URL is public and APP_ENV is neither production nor staging (P-4)', function () {
    $message = 'APP_ENV must be "production" (or "staging") when APP_URL is not a local address.';

    config(['app.url' => 'https://api.quinvite.com']);
    expect(ProductionSafetyCheck::problems())->toContain($message);

    foreach (['production', 'staging'] as $env) {
        $this->app['env'] = $env;
        expect(ProductionSafetyCheck::problems())->not->toContain($message);
    }

    $this->app['env'] = 'local';
    foreach (['http://localhost', 'http://127.0.0.1:8000', 'http://dev.api.cwm.com', 'http://app.test', 'http://[::1]'] as $url) {
        config(['app.url' => $url]);
        expect(ProductionSafetyCheck::problems())->not->toContain($message);
    }
});

it('refuses the fake payment gateway outside local and testing (P-4)', function () {
    $purchases = app(PurchaseService::class);
    expect($purchases->gateway('fake'))->toBeInstanceOf(FakeGateway::class);

    foreach (['staging', 'production', 'development'] as $env) {
        $this->app['env'] = $env;
        expect(fn () => $purchases->gateway('fake'))->toThrow(RuntimeException::class);
    }
    $this->app['env'] = 'local';
    expect($purchases->gateway('fake'))->toBeInstanceOf(FakeGateway::class);
});

// ---- Deployment warnings (staging readiness, A-2 and the 2026-11-20 configuration audit) ----

function deployedConfig(array $over = []): void
{
    config($over + [
        'app.url' => 'https://api.quinvite.com', 'app.trusted_proxies' => '10.0.0.0/8',
        'services.hcaptcha.enabled' => true, 'services.hcaptcha.site_key' => 'k', 'services.hcaptcha.secret' => 's',
        'queue.default' => 'redis', 'mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.resend.com',
        'mail.mailers.smtp.username' => 'resend', 'mail.mailers.smtp.password' => 'x', 'mail.from.address' => 'hola@quinvite.com',
        'filesystems.default' => 'r2', 'filesystems.media_disk' => 'r2', 'invoicing.disk' => 'r2_private',
        'cache.default' => 'redis', 'session.driver' => 'redis', 'database.redis.default.host' => 'redis.internal', 'session.domain' => null,
        'filesystems.disks.r2' => ['driver' => 's3', 'key' => 'k', 'secret' => 's', 'endpoint' => 'https://x.r2', 'bucket' => 'b', 'url' => 'https://cdn.quinvite.com'],
        'filesystems.disks.r2_private' => ['driver' => 's3', 'key' => 'k', 'secret' => 's', 'endpoint' => 'https://x.r2', 'bucket' => 'p'],
        'filesystems.disks.template-packages' => ['driver' => 's3', 'key' => 'k', 'secret' => 's', 'endpoint' => 'https://x.r2', 'bucket' => 'pk', 'url' => 'https://packages.example.net'],
    ]);
}

it('reports no warnings outside a deployed environment', function () {
    deployedConfig(['app.trusted_proxies' => '', 'queue.default' => 'sync']);

    expect(ProductionSafetyCheck::warnings())->toBe([]); // APP_ENV=testing

    $this->app['env'] = 'staging';
    config(['app.url' => 'http://dev.api.cwm.com']);
    expect(ProductionSafetyCheck::warnings())->toBe([]); // staging on a local URL
});

it('has no warnings for a proper deployed configuration', function (string $env) {
    $this->app['env'] = $env;
    deployedConfig();

    expect(ProductionSafetyCheck::warnings())->toBe([]);
})->with(['production', 'staging']);

it('warns when TRUSTED_PROXIES is empty or a wildcard on staging and production (A-2)', function (string $env, string $value, string $needle) {
    $this->app['env'] = $env;
    deployedConfig(['app.trusted_proxies' => $value]);

    $warnings = ProductionSafetyCheck::warnings();
    expect($warnings)->toHaveCount(1)->and($warnings[0])->toContain($needle);
})->with([
    ['staging', '', 'TRUSTED_PROXIES is empty'],
    ['production', '  ', 'TRUSTED_PROXIES is empty'],
    ['staging', '*', 'forge X-Forwarded-For'],
]);

it('warns about hCaptcha, queue, mail, storage, cache and session settings that do not fit a deployed server', function () {
    $this->app['env'] = 'production';
    deployedConfig([
        'services.hcaptcha.enabled' => false, 'queue.default' => 'sync',
        'mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'cwm_mailpit', 'mail.mailers.smtp.username' => null, 'mail.from.address' => 'hello@example.com',
        'filesystems.default' => 'local', 'filesystems.media_disk' => 'public',
        'filesystems.disks.public' => ['driver' => 'local'], 'invoicing.disk' => 'local',
        'cache.default' => 'file', 'session.driver' => 'array',
    ]);

    $text = implode("\n", ProductionSafetyCheck::warnings());
    foreach (['HCAPTCHA_ENABLED is false', 'QUEUE_CONNECTION is "sync"', 'local mail catcher', 'MAIL_USERNAME and MAIL_PASSWORD', 'MAIL_FROM_ADDRESS',
        'FILESYSTEM_DISK is "local"', 'MEDIA_DISK is "public"', 'INVOICING_DISK is "local"', 'CACHE_STORE is "file"', 'SESSION_DRIVER is "array"'] as $needle) {
        expect($text)->toContain($needle);
    }
});

it('names the missing R2 variables, once each, and the hCaptcha keys', function () {
    $this->app['env'] = 'production';
    $empty = ['driver' => 's3', 'key' => null, 'secret' => '', 'endpoint' => null, 'bucket' => null, 'url' => null];
    deployedConfig([
        'services.hcaptcha.secret' => null, 'mail.default' => 'log',
        'filesystems.disks.r2' => $empty, 'filesystems.disks.r2_private' => $empty, 'filesystems.disks.template-packages' => $empty,
        'database.redis.default.host' => '127.0.0.1',
    ]);

    $warnings = ProductionSafetyCheck::warnings();
    $text = implode("\n", $warnings);
    foreach (['R2_ACCESS_KEY_ID', 'R2_SECRET_ACCESS_KEY', 'R2_ENDPOINT', 'R2_BUCKET', 'R2_URL', 'R2_PRIVATE_BUCKET', 'R2_PACKAGES_BUCKET', 'TEMPLATE_PACKAGES_URL',
        'HCAPTCHA_SITE_KEY and HCAPTCHA_SECRET', 'MAIL_MAILER is "log"', 'REDIS_HOST/REDIS_URL'] as $needle) {
        expect($text)->toContain($needle);
    }
    expect(collect($warnings)->filter(fn ($w) => str_starts_with($w, 'R2_ACCESS_KEY_ID'))->count())->toBe(1);
});

it('warns when the session cookie domain also covers the media origin (M3-3) and says nothing when it does not', function () {
    $this->app['env'] = 'production';
    deployedConfig(['session.domain' => '.quinvite.com']);
    expect(implode("\n", ProductionSafetyCheck::warnings()))->toContain('SESSION_DOMAIN (.quinvite.com) also covers the host of R2_URL (cdn.quinvite.com)');

    deployedConfig(['session.domain' => '.quinvite.com', 'filesystems.disks.r2' => ['driver' => 's3', 'key' => 'k', 'secret' => 's', 'endpoint' => 'e', 'bucket' => 'b', 'url' => 'https://media.example.net']]);
    expect(ProductionSafetyCheck::warnings())->toBe([]);
});

it('prints the warnings in app:doctor and fails on them only with --strict', function () {
    $this->app['env'] = 'staging';
    deployedConfig(['app.trusted_proxies' => '']);

    $this->artisan('app:doctor')->expectsOutputToContain('WARNING')->assertSuccessful();
    $this->artisan('app:doctor --strict')->assertFailed();

    deployedConfig();
    $this->artisan('app:doctor --strict')->expectsOutputToContain('No warnings.')->assertSuccessful();
});
