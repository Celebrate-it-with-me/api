<?php

namespace App\Support;

/** Human summaries of a user agent and an IP for the "sessions" and "activity" lists of Mi cuenta. Pure functions. */
final class ClientInfo
{
    /** "Chrome en Windows" from a raw user agent; "Dispositivo desconocido" when there is nothing to read. */
    public static function device(?string $userAgent): string
    {
        $ua = (string) $userAgent;
        if ($ua === '') {
            return 'Dispositivo desconocido';
        }

        $browser = match (true) {
            (bool) preg_match('~Edg(e|A|iOS)?/~', $ua) => 'Edge',
            (bool) preg_match('~OPR/|Opera~', $ua) => 'Opera',
            (bool) preg_match('~Firefox/|FxiOS/~', $ua) => 'Firefox',
            (bool) preg_match('~Chrome/|CriOS/~', $ua) => 'Chrome',
            (bool) preg_match('~Safari/~', $ua) => 'Safari',
            default => null,
        };
        $os = match (true) {
            (bool) preg_match('~Android~', $ua) => 'Android',
            (bool) preg_match('~iPhone|iPad|iPod~', $ua) => 'iOS',
            (bool) preg_match('~Windows~', $ua) => 'Windows',
            (bool) preg_match('~Mac OS X|Macintosh~', $ua) => 'macOS',
            (bool) preg_match('~CrOS~', $ua) => 'ChromeOS',
            (bool) preg_match('~Linux~', $ua) => 'Linux',
            default => null,
        };

        return match (true) {
            $browser && $os => "{$browser} en {$os}",
            (bool) $browser => $browser,
            (bool) $os => $os,
            default => 'Dispositivo desconocido',
        };
    }

    /** Last part of the address hidden: 190.12.34.56 becomes 190.12.34.x, 2001:db8:1:2:: becomes 2001:db8:1:x. */
    public static function maskIp(?string $ip): ?string
    {
        if (! $ip) {
            return null;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            $parts[3] = 'x';

            return implode('.', $parts);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $groups = explode(':', (string) inet_ntop((string) inet_pton($ip)));

            return implode(':', array_slice($groups, 0, 3)).':x';
        }

        return null;
    }
}
