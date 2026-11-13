<?php

namespace App\Http\Services\Admin\EventActions;

/** Hides contact data of guests and padrinos from staff screens: enough to recognise it, not to use it. */
final class Mask
{
    /** `+13055550149` -> `+1 *** ** 0149` */
    public static function phone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if ($digits === '') {
            return null;
        }
        if (strlen($digits) < 6) {
            return '***';
        }
        $prefix = str_starts_with(trim((string) $phone), '+') ? '+' . $digits[0] . ' ' : '';

        return $prefix . '*** ** ' . substr($digits, -4);
    }

    /** `ana@gmail.com` -> `a***@gmail.com` */
    public static function email(?string $email): ?string
    {
        if (! $email || ! str_contains($email, '@')) {
            return null;
        }
        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 1) . '***@' . $domain;
    }
}
