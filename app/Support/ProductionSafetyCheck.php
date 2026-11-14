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
        if (blank(config('sanctum.stateful')) || array_filter(config('sanctum.stateful'), fn ($d) => preg_match('/^(localhost|127\.|::1|.*\.cwm\.com)/', $d))) {
            $problems[] = 'SANCTUM_STATEFUL_DOMAINS must list only the real SPA domains.';
        }

        return $problems;
    }
}
