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
        if (config('services.payments.gateway') === 'fake') {
            $problems[] = 'PAYMENTS_GATEWAY must not be "fake".';
        }
        if (blank(config('services.payments.webhook_secret'))) {
            $problems[] = 'PAYMENTS_WEBHOOK_SECRET must be set.';
        }
        if (config('session.secure') !== true) {
            $problems[] = 'SESSION_SECURE_COOKIE must be true.';
        }
        $stateful = array_values(array_filter((array) config('sanctum.stateful'), fn ($d) => trim((string) $d) !== ''));
        if ($stateful === [] || array_filter($stateful, fn ($d) => preg_match('/^(localhost|127\.|::1|.*\.cwm\.com)/', $d))) {
            $problems[] = 'SANCTUM_STATEFUL_DOMAINS must list only the real SPA domains.';
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
