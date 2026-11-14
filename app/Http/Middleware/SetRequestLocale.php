<?php

namespace App\Http\Middleware;

use App\Support\Mail\MailLocale;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * One predictable language for the text the API sends back (validation errors and messages a person reads):
 * the `lang` parameter or X-Locale header (explicit), then the language saved in the signed-in account's
 * preferences, then the Accept-Language header (what an anonymous browser says), then Spanish. Only es and en exist; anything else is skipped. Error codes never depend on it.
 */
class SetRequestLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale($this->resolve($request));

        return $next($request);
    }

    public function resolve(Request $request): string
    {
        // What the client states on purpose: `lang` parameter or X-Locale header.
        $explicit = $this->supported($request->query('lang') ?? $request->input('lang') ?? $request->header('X-Locale'));
        if ($explicit) {
            return $explicit;
        }

        // The language saved by the signed-in account. It goes before Accept-Language because a browser sends that
        // header on its own (the language of the system), so it says nothing about what she chose in the product.
        // A bearer token is read here (the auth middleware has not run yet); a bad or missing one is just "no user".
        $user = $request->bearerToken() ? $request->user('sanctum') : null;
        if ($saved = $this->supported($user?->preferences?->language)) {
            return $saved;
        }

        // Anonymous requests (sign-in, register, reset): the browser language is the only hint there is.
        foreach ($this->acceptedLanguages((string) $request->header('Accept-Language')) as $candidate) {
            if ($found = $this->supported($candidate)) {
                return $found;
            }
        }

        return MailLocale::DEFAULT;
    }

    private function supported(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $code = strtolower(substr(trim($value), 0, 2));

        return in_array($code, MailLocale::SUPPORTED, true) ? $code : null;
    }

    /** Language tags of an Accept-Language header ordered by their q weight (ties keep the written order). */
    private function acceptedLanguages(string $header): array
    {
        $weighted = [];
        foreach (explode(',', $header) as $position => $part) {
            $pieces = explode(';', trim($part));
            $tag = trim($pieces[0]);
            if ($tag === '' || $tag === '*') {
                continue;
            }
            $q = 1.0;
            foreach (array_slice($pieces, 1) as $param) {
                if (preg_match('/^\s*q\s*=\s*([0-9.]+)\s*$/i', $param, $m)) {
                    $q = (float) $m[1];
                }
            }
            if ($q > 0) {
                $weighted[] = [$q, $position, $tag];
            }
        }

        usort($weighted, fn ($a, $b) => [$b[0], $a[1]] <=> [$a[0], $b[1]]);

        return array_column($weighted, 2);
    }
}
