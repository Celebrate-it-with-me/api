<?php

namespace App\Support;

use Illuminate\Support\Facades\Hash;

/**
 * Password check whose cost does not depend on the account existing (F-3). The stand-in hash is produced by
 * Hash::make with the configured driver and cost, so it is the same algorithm and work as a real stored hash.
 */
class TimingSafePassword
{
    private static ?string $dummyHash = null;

    public static function dummyHash(): string
    {
        if (self::$dummyHash === null) {
            $hash = (string) Hash::make('not-a-real-password');
            // A mocked Hash facade answers nothing: use it for this call but do not remember it.
            if ($hash === '') {
                return $hash;
            }
            self::$dummyHash = $hash;
        }

        return self::$dummyHash;
    }

    /** Hash::check against the account hash, or against the stand-in when there is none. Always runs one check. */
    public static function check(mixed $plain, ?string $storedHash): bool
    {
        $plain = is_string($plain) ? $plain : '';

        return (bool) Hash::check($plain, $storedHash ?? self::dummyHash());
    }
}
