<?php

namespace App\Http\Services\Admin\Health;

/**
 * Turns an error message from a log or a failed job into a short line that is safe to show to staff:
 * no emails, phones, tokens, SQL values, file system paths or stack traces.
 */
final class Redactor
{
    public static function summary(?string $text, int $max = 200): string
    {
        $text = (string) $text;

        // Only the first line: the rest is a trace or a context dump.
        $text = trim((string) preg_split('/\R/', trim($text), 2)[0]);

        // Queries carry the values being bound: keep the error, drop the query.
        $text = (string) preg_replace('/\s*\((?:Connection|SQL):.*$/is', '', $text);
        $text = (string) preg_replace('/\s*\(Connection:.*$/is', '', $text);
        $text = (string) preg_replace('/\s+in\s+\/\S+(?::\d+)?/', '', $text);
        // Context JSON appended by the logger.
        $text = (string) preg_replace('/\s+[\{\[]"?.*$/s', '', $text);

        // Postgres: Key (email)=(someone@x.com) already exists.
        $text = (string) preg_replace('/Key \(([^)]*)\)=\(([^)]*)\)/', 'Key ($1)=([valor])', $text);
        $text = (string) preg_replace('/[\w.+\-]+@[\w\-]+(?:\.[\w\-]+)+/', '[correo]', $text);
        $text = (string) preg_replace('/\b(?:Bearer|Basic)\s+\S+/i', '[token]', $text);
        $text = (string) preg_replace('/\b\d+\|[A-Za-z0-9]{20,}\b/', '[token]', $text);
        $text = (string) preg_replace('/\b(?:sk|pk|rk|whsec|ya29|EAA)[_A-Za-z0-9\-]{12,}/', '[token]', $text);
        $text = (string) preg_replace('/\b(?=[A-Za-z0-9_\-+\/=]*\d)(?=[A-Za-z0-9_\-+\/=]*[A-Za-z])[A-Za-z0-9_\-+\/=]{24,}\b/', '[token]', $text);
        $text = (string) preg_replace('/(?<![\w])\+?\d[\d\s().\-]{8,}\d(?![\w])/', '[numero]', $text);
        $text = (string) preg_replace('/\b(password|secret|token|api[_-]?key|authorization)\b\s*[=:]\s*\S+/i', '$1=[oculto]', $text);
        // Values in quotes could be anything a guest typed.
        $text = (string) preg_replace("/'[^']{0,200}'/", "'…'", $text);

        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1).'…' : $text;
    }
}
