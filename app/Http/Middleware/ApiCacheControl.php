<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * JSON of the API (sessions, tokens, guests, invoices) must not be stored by browsers, proxies or a CDN: Laravel's
 * implicit `no-cache, private` still lets a browser keep a copy on disk. Whatever a controller sets on purpose (the
 * public invitation, downloads) is left untouched; only the implicit default is hardened.
 */
class ApiCacheControl
{
    private const LARAVEL_DEFAULT = 'no-cache, private';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->headers->get('Cache-Control') === self::LARAVEL_DEFAULT
            && str_contains((string) $response->headers->get('Content-Type'), 'json')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
