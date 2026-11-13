<?php

namespace App\Support;

/**
 * Phone numbers as the app stores them: international format (E.164, "+13055550101").
 *
 * No library: the first market is the U.S., and the rules below cover what people actually type.
 * A number without a country code is assumed to be a U.S. one when it has exactly 10 digits, and the
 * caller is told so (`assumed_us`) to show it in the import preview.
 */
class PhoneNumber
{
    public const OK = 'ok';

    public const ASSUMED_US = 'assumed_us';

    public const INVALID = 'invalid';

    public const EMPTY = 'empty';

    /** @return array{e164: ?string, status: string} */
    public static function normalize(?string $raw): array
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return ['e164' => null, 'status' => self::EMPTY];
        }

        // Excel turns long numbers into 1.3055550101E+10 or adds ".0": undo the usual cases.
        if (preg_match('/^\d+\.0+$/', $raw)) {
            $raw = explode('.', $raw)[0];
        }

        $international = str_starts_with($raw, '+') || str_starts_with($raw, '00');
        $digits = preg_replace('/\D+/', '', $raw);
        if (str_starts_with($raw, '00')) {
            $digits = substr($digits, 2);
        }

        if ($international) {
            return self::fits($digits) ? ['e164' => '+' . $digits, 'status' => self::OK] : ['e164' => null, 'status' => self::INVALID];
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return ['e164' => '+' . $digits, 'status' => self::OK];
        }

        if (strlen($digits) === 10) {
            return ['e164' => '+1' . $digits, 'status' => self::ASSUMED_US];
        }

        return ['e164' => null, 'status' => self::INVALID];
    }

    /** E.164 allows 8 to 15 digits (we ask for at least 8 so a short local number is not taken as international). */
    private static function fits(string $digits): bool
    {
        return strlen($digits) >= 8 && strlen($digits) <= 15 && $digits[0] !== '0';
    }
}
