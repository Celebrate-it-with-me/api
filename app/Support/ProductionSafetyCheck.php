<?php

namespace App\Support;

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
        $driver = config('services.whatsapp.driver');
        if ($driver === 'fake') {
            $problems[] = 'WHATSAPP_DRIVER must not be "fake".';
        } elseif ($driver === 'meta') {
            foreach (['app_secret' => 'WHATSAPP_APP_SECRET', 'verify_token' => 'WHATSAPP_VERIFY_TOKEN'] as $key => $env) {
                if (blank(config("services.whatsapp.$key"))) {
                    $problems[] = "$env must be set.";
                }
            }
        }

        return $problems;
    }
}
