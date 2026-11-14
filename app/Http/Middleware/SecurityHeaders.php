<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Headers every API response carries. The API only answers JSON/files: nothing in it is meant to be framed or
 * interpreted as a page, so the policy is the strictest one. (Files under /storage are served by the web server:
 * give it the same `nosniff` header there, ideally from a separate domain.)
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Only JSON gets the locked-down policy: PDFs, HTML previews and the OG page keep working.
        if (str_contains((string) $response->headers->get('Content-Type'), 'json')) {
            $response->headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        }

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
