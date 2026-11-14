<?php

namespace App\Support\WhatsApp;

/** Phone numbers for WhatsApp: E.164 in, masked out. Guests' phones are free text, so we normalize before sending. */
final class Phone
{
    /** `+13055550123`, or null when it cannot be one. A bare 10-digit number is taken as US/Canada (+1). */
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $raw = trim($raw);
        $digits = preg_replace('/\D+/', '', $raw);

        if (str_starts_with($raw, '+') || str_starts_with($raw, '00')) {
            $digits = str_starts_with($raw, '00') ? substr($digits, 2) : $digits;
        } elseif (strlen($digits) === 10) {
            $digits = '1' . $digits;
        } elseif (! (strlen($digits) === 11 && $digits[0] === '1')) {
            return null;
        }

        return self::valid('+' . $digits) ? '+' . $digits : null;
    }

    public static function valid(string $e164): bool
    {
        return (bool) preg_match('/^\+[1-9]\d{7,14}$/', $e164);
    }

    /** `+1305***0123`. Never log or show a full number outside the organizer's own guest list. */
    public static function mask(?string $e164): ?string
    {
        if ($e164 === null || $e164 === '') {
            return null;
        }
        $len = strlen($e164);

        return $len <= 9 ? str_repeat('*', $len) : substr($e164, 0, 5) . '***' . substr($e164, -4);
    }

    /** `...1234` for ids shown in the admin. */
    public static function tail(?string $value): ?string
    {
        return $value ? '...' . substr($value, -4) : null;
    }
}
