<?php

namespace App\Support;

use App\Http\Services\WhatsApp\WhatsAppService;

/**
 * What must not be true on a production server. Run on boot (web requests only) and by `php artisan app:doctor`, so a
 * copied development `.env` fails loudly instead of running with debug pages, plain cookies or the fake gateway.
 */
class ProductionSafetyCheck
{
    /** @return list<string> */
    public static function problems(): array
    {
        $problems = [];

        // The checks above only bite with APP_ENV=production (P-4): a public URL under another name runs unprotected.
        if (! app()->environment('production', 'staging') && ! self::isLocalUrl((string) config('app.url'))) {
            $problems[] = 'APP_ENV must be "production" (or "staging") when APP_URL is not a local address.';
        }

        if (config('app.debug')) {
            $problems[] = 'APP_DEBUG must be false.';
        }
        $gateway = config('services.payments.gateway');
        if ($gateway === 'fake') {
            $problems[] = 'PAYMENTS_GATEWAY must not be "fake".';
        } elseif (! in_array($gateway, ['stripe', 'manual'], true)) {
            $problems[] = 'PAYMENTS_GATEWAY must be "stripe" (or "manual").';
        }
        if ($gateway === 'stripe') {
            // Stripe signs with its own secret; the local one can sign nothing.
            $secret = (string) config('services.stripe.secret');
            if ($secret === '') {
                $problems[] = 'STRIPE_SECRET must be set.';
            } elseif (! preg_match('/^(sk|rk)_live_/', $secret)) {
                $problems[] = 'STRIPE_SECRET must be a live key (sk_live_ or rk_live_), not a test key.';
            }
            if (blank(config('services.stripe.webhook_secret'))) {
                $problems[] = 'STRIPE_WEBHOOK_SECRET must be set.';
            }
        } elseif (blank(config('services.payments.webhook_secret'))) {
            $problems[] = 'PAYMENTS_WEBHOOK_SECRET must be set.';
        }
        if (config('session.secure') !== true) {
            $problems[] = 'SESSION_SECURE_COOKIE must be true.';
        }
        $stateful = array_values(array_filter((array) config('sanctum.stateful'), fn ($d) => trim((string) $d) !== ''));
        if ($stateful === [] || array_filter($stateful, fn ($d) => preg_match('/^(localhost|127\.|::1|.*\.cwm\.com)/', $d))) {
            $problems[] = 'SANCTUM_STATEFUL_DOMAINS must list only the real SPA domains.';
        }

        // Superadmins must use two-factor in production (audit A-3). It defaults to on there; only an explicit false turns it off.
        if (config('admin.require_two_factor_superadmin') !== true) {
            $problems[] = 'ADMIN_REQUIRE_2FA_SUPERADMIN must be true.';
        }

        // Package files must have their own origin (W6-08): without it they would be addressed on the API's origin.
        if (! app()->environment('local', 'testing') && blank(config('filesystems.disks.template-packages.url'))) {
            $problems[] = 'TEMPLATE_PACKAGES_URL must be set (an origin without cookies, serving the headers of the spec 5.2).';
        }

        // WhatsApp (audit W-10): the fake driver "sends" nothing; Meta without its secrets leaves the webhook answering 403.
        // Strict (P-3): the service runs as `fake` for ANYTHING but `meta`, so anything else must be reported here.
        $driver = WhatsAppService::normalizeDriver(config('services.whatsapp.driver'));
        if ($driver === 'fake') {
            $problems[] = 'WHATSAPP_DRIVER must not be "fake".';
        } elseif ($driver !== 'meta') {
            $problems[] = 'WHATSAPP_DRIVER must be "meta" (any other value runs as the fake driver).';
        } else {
            foreach (['app_secret' => 'WHATSAPP_APP_SECRET', 'verify_token' => 'WHATSAPP_VERIFY_TOKEN'] as $key => $env) {
                if (blank(config("services.whatsapp.$key"))) {
                    $problems[] = "$env must be set.";
                }
            }
        }

        return $problems;
    }

    /**
     * Things that do not stop a boot but that make a deployed server misbehave (staging checklist, A-2 and the 2026-11-20
     * configuration audit). Only reported on production, and on staging when APP_URL is a real address: a local
     * environment is expected to run with the fake gateway, `sync` queues and so on. Each entry names the variable.
     *
     * @return list<string>
     */
    public static function warnings(): array
    {
        $deployed = app()->environment('production') || (app()->environment('staging') && ! self::isLocalUrl((string) config('app.url')));
        if (! $deployed) {
            return [];
        }

        $warnings = [];

        // A-2: behind a load balancer every request comes from the balancer's address unless its headers are trusted.
        $proxies = trim((string) config('app.trusted_proxies', ''));
        if ($proxies === '') {
            $warnings[] = 'TRUSTED_PROXIES is empty: behind a load balancer every visitor shares one IP, so the per-IP limits (access code gate, login, webhooks) count the balancer as one person. Set the balancer\'s addresses (read them from the Laravel Cloud and Cloudflare documentation).';
        } elseif ($proxies === '*') {
            $warnings[] = 'TRUSTED_PROXIES is "*": any client can forge X-Forwarded-For and dodge the per-IP limits unless the balancer overwrites that header. Prefer the balancer\'s real addresses.';
        }

        if (config('services.hcaptcha.enabled') !== true) {
            $warnings[] = 'HCAPTCHA_ENABLED is false: register and login have no bot protection.';
        } elseif (blank(config('services.hcaptcha.site_key')) || blank(config('services.hcaptcha.secret'))) {
            $warnings[] = 'HCAPTCHA_SITE_KEY and HCAPTCHA_SECRET must be set while HCAPTCHA_ENABLED is true.';
        }

        $queue = (string) config('queue.default');
        if (in_array($queue, ['sync', 'null'], true)) {
            $warnings[] = 'QUEUE_CONNECTION is "' . $queue . '": mail, invoices and WhatsApp run inside the request (or never). Use redis (or database) and run a worker.';
        }

        $warnings = [...$warnings, ...self::mailWarnings(), ...self::storageWarnings(), ...self::stateWarnings()];

        // M3-3: a session cookie for `.quinvite.com` is also sent to `cdn.quinvite.com`. Informational: the owner decides.
        $sessionDomain = ltrim(strtolower((string) config('session.domain')), '.');
        if ($sessionDomain !== '') {
            foreach (['filesystems.disks.r2.url' => 'R2_URL', 'filesystems.disks.template-packages.url' => 'TEMPLATE_PACKAGES_URL'] as $key => $env) {
                $host = strtolower((string) parse_url((string) config($key), PHP_URL_HOST));
                if ($host !== '' && ($host === $sessionDomain || str_ends_with($host, '.' . $sessionDomain))) {
                    $warnings[] = "SESSION_DOMAIN (.$sessionDomain) also covers the host of $env ($host): the session cookie travels to that origin (risk M3-3, owner decision).";
                }
            }
        }

        return $warnings;
    }

    /** @return list<string> */
    private static function mailWarnings(): array
    {
        $warnings = [];
        $mailer = (string) config('mail.default');
        if (in_array($mailer, ['log', 'array'], true)) {
            $warnings[] = 'MAIL_MAILER is "' . $mailer . '": no mail leaves the server. Use smtp with Resend.';
        } elseif ($mailer === 'smtp') {
            $host = strtolower((string) config('mail.mailers.smtp.host'));
            if ($host === '' || self::isLocalUrl('//' . $host) || str_contains($host, 'mailpit') || str_contains($host, 'mailhog')) {
                $warnings[] = 'MAIL_HOST points at a local mail catcher ("' . $host . '"); use smtp.resend.com.';
            }
            if (blank(config('mail.mailers.smtp.username')) || blank(config('mail.mailers.smtp.password'))) {
                $warnings[] = 'MAIL_USERNAME and MAIL_PASSWORD must be set (Resend: user "resend", password = API key).';
            }
        }
        if (str_ends_with(strtolower((string) config('mail.from.address')), '@example.com')) {
            $warnings[] = 'MAIL_FROM_ADDRESS is still the example address; use a sender of the verified domain.';
        }

        return $warnings;
    }

    /** @return list<string> */
    private static function storageWarnings(): array
    {
        $warnings = [];
        $disks = ['media' => (string) config('filesystems.media_disk')];
        if (config('filesystems.default') === 'local') {
            $warnings[] = 'FILESYSTEM_DISK is "local": theme assets live on the container disk, which a PaaS wipes on every deploy. Use r2.';
        }
        $disks['invoices'] = (string) config('invoicing.disk');
        $disks['template-packages'] = 'template-packages';

        // [env of the bucket, whether the disk needs a public URL]
        $bucketEnv = ['r2' => ['R2_BUCKET', true], 'public' => ['R2_BUCKET', true], 'r2_private' => ['R2_PRIVATE_BUCKET', false], 'template-packages' => ['R2_PACKAGES_BUCKET', true]];
        $reported = [];
        foreach ($disks as $purpose => $disk) {
            $config = config("filesystems.disks.$disk");
            if (! is_array($config) || ($config['driver'] ?? null) === 'local') {
                if ($purpose !== 'template-packages') {
                    $env = $purpose === 'media' ? 'MEDIA_DISK' : 'INVOICING_DISK';
                    $warnings[] = "$env is \"$disk\", a local disk: files vanish on a PaaS. Use r2" . ($purpose === 'invoices' ? '_private.' : '.');
                } else {
                    $warnings[] = 'TEMPLATE_PACKAGES_DRIVER is local: packages live on the container disk. Use r2.';
                }

                continue;
            }
            [$bucket, $public] = $bucketEnv[$disk] ?? ['R2_BUCKET', true];
            $needs = ['R2_ACCESS_KEY_ID' => $config['key'] ?? null, 'R2_SECRET_ACCESS_KEY' => $config['secret'] ?? null, 'R2_ENDPOINT' => $config['endpoint'] ?? null, $bucket => $config['bucket'] ?? null];
            if ($public) {
                $needs[$disk === 'template-packages' ? 'TEMPLATE_PACKAGES_URL' : 'R2_URL'] = $config['url'] ?? null;
            }
            foreach ($needs as $env => $value) {
                if (blank($value) && ! isset($reported[$env])) {
                    $reported[$env] = true;
                    $warnings[] = "$env must be set (disk \"$disk\" for $purpose).";
                }
            }
        }

        return $warnings;
    }

    /** Cache, sessions and queue must live somewhere every instance sees, and Redis must be reachable by name. @return list<string> */
    private static function stateWarnings(): array
    {
        $warnings = [];
        $cache = (string) config('cache.default');
        $session = (string) config('session.driver');
        foreach (['CACHE_STORE' => $cache, 'SESSION_DRIVER' => $session] as $env => $value) {
            if (in_array($value, ['file', 'array'], true)) {
                $warnings[] = "$env is \"$value\": each instance keeps its own copy (rate limits, sessions). Use redis.";
            }
        }
        $usesRedis = $cache === 'redis' || $session === 'redis' || config('queue.default') === 'redis';
        if ($usesRedis) {
            $host = strtolower((string) config('database.redis.default.host'));
            if (blank(config('database.redis.default.url')) && ($host === '' || in_array($host, ['127.0.0.1', 'localhost', 'cwm_redis', 'redis'], true))) {
                $warnings[] = 'Redis is in use but REDIS_HOST/REDIS_URL point at "' . $host . '": set the managed Redis address.';
            }
        }

        return $warnings;
    }

    /** Development hosts: localhost, loopback, .test/.localhost/.local and the project's own *.cwm.com. */
    public static function isLocalUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host === '' || (bool) preg_match('/^(localhost|127\.|\[?::1\]?$)|\.(test|localhost|local)$|(^|\.)cwm\.com$/', $host);
    }
}
