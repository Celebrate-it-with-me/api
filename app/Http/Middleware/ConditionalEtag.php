<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `ETag` + `If-None-Match` -> `304 Not Modified` for GET/HEAD answers that are safe to revalidate (the public
 * invitation). The tag is a hash of the body this very request produced, so it can never describe another guest's
 * payload: what differs per guest is inside the body. Cache-Control is left to the route (it is the controller's call).
 */
class ConditionalEtag
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->isMethodCacheable() || $response->getStatusCode() !== 200 || $response->headers->has('ETag')) {
            return $response;
        }

        $response->setEtag(md5((string) $response->getContent()), true);
        $response->isNotModified($request);

        return $response;
    }
}
