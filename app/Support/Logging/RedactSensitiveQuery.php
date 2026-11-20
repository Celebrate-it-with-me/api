<?php

namespace App\Support\Logging;

/**
 * Masks the value of secret-looking parameters in query strings found in free text (B3-2): signed links, OAuth callbacks,
 * invitation codes and anything an HTTP client exception echoes back. Only `?name=value` / `&name=value` pairs are touched,
 * so ordinary text such as "code=429" outside a URL stays readable.
 */
final class RedactSensitiveQuery
{
    /** Parameter names (case-insensitive). */
    public const KEYS = ['token', 'signature', 'hash', 'code', 'password', 'password_confirmation', 'current_password', 'access_token', 'refresh_token', 'id_token', 'client_secret', 'api_key', 'secret'];

    public const MASK = '[redacted]';

    public static function in(string $text): string
    {
        if (! str_contains($text, '=')) {
            return $text;
        }

        // Separators: `?`, `&`, and the escapes of `&` inside JSON (&) and HTML (&amp;). The value ends at the next separator, quote, space or fragment.
        $keys = implode('|', array_map('preg_quote', self::KEYS));

        return (string) preg_replace(
            '/(?<sep>[?&]|\\\\u0026|&amp;)(?<key>' . $keys . ')=(?<value>(?:(?!&amp;)[^&\s"\'#\\\\<>])*)/i',
            '$1$2=' . self::MASK,
            $text,
        );
    }
}
