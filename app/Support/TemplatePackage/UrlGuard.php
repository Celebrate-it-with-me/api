<?php

namespace App\Support\TemplatePackage;

/**
 * The one rule for every external URL a package may carry (manifest author/credits/marks and HTML links): `https:`
 * only, no credentials, a public-looking DNS name (no `localhost`, no IP literal in any notation, no single-label or
 * internal TLD), ASCII without whitespace or control characters. A link the guest can click, never a request the
 * server or the page makes by itself.
 */
final class UrlGuard
{
    public const MAX_LENGTH = 300;

    private const INTERNAL_TLDS = ['localhost', 'local', 'internal', 'intranet', 'lan', 'home', 'corp', 'private', 'localdomain', 'onion', 'test', 'invalid', 'arpa'];

    public static function isHttps(mixed $url): bool
    {
        if (! is_string($url) || $url === '' || strlen($url) > self::MAX_LENGTH || ! preg_match('/^[\x21-\x7E]+$/', $url)) {
            return false;
        }
        if (! preg_match('/^https:\/\/([^\/?#]*)([\/?#].*)?$/i', $url, $m)) {
            return false;
        }
        $authority = $m[1];
        if (str_contains($authority, '@') || str_contains($authority, '\\') || str_contains($authority, '[')) {
            return false;
        }
        if (! preg_match('/^([a-z0-9.-]+)(?::(\d{1,5}))?$/i', $authority, $a)) {
            return false;
        }
        if (isset($a[2]) && ((int) $a[2] < 1 || (int) $a[2] > 65535)) {
            return false;
        }
        $host = strtolower($a[1]);
        if (strlen($host) > 253 || ! str_contains($host, '.')) {
            return false;
        }
        $labels = explode('.', $host);
        foreach ($labels as $label) {
            if (! preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $label)) {
                return false;
            }
        }
        $tld = end($labels);
        // An alphabetic TLD rules out IPv4 in decimal, octal, hex or short forms (all end in a number).
        if (! preg_match('/^(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/', $tld) || in_array($tld, self::INTERNAL_TLDS, true)) {
            return false;
        }

        return true;
    }

    /** An optional manifest URL: absent / null is fine, anything else must be a safe https URL. */
    public static function isOptionalHttps(mixed $url): bool
    {
        return $url === null || self::isHttps($url);
    }
}
