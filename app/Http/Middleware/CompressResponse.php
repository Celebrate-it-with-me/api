<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * gzip for the text responses of the API (JSON, CSS, SVG, calendar files), when the client asks for it.
 *
 * Who does what in production: a PaaS router or a CDN usually compresses (and does brotli) in front of the app; when
 * it does, set HTTP_COMPRESSION=false so PHP does not spend CPU on it. When the app is reached directly (local Docker,
 * a plain VM), this middleware is the only compression there is. It never compresses twice: a response that already
 * has a `Content-Encoding` is left alone, and a proxy that sees `Content-Encoding: gzip` passes it through.
 * Brotli is deliberately not done here (no PHP extension in the image): leave it to the proxy/CDN.
 *
 * Small bodies are not worth the CPU; images, fonts and downloads are already compressed (or not text) and are skipped.
 */
class CompressResponse
{
    /** Below this many bytes the gain is lost in the headers. */
    private const MIN_BYTES = 1024;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! config('http.compression.enabled', true) || ! $this->acceptsGzip($request) || ! $this->compressible($response)) {
            return $response;
        }

        $content = (string) $response->getContent();

        // Whatever the answer, the representation depends on the client's Accept-Encoding.
        $response->setVary('Accept-Encoding', false);

        if (strlen($content) < self::MIN_BYTES) {
            return $response;
        }

        $encoded = gzencode($content, (int) config('http.compression.level', 5));

        if ($encoded === false) {
            return $response;
        }

        $response->setContent($encoded);
        $response->headers->set('Content-Encoding', 'gzip');
        $response->headers->set('Content-Length', (string) strlen($encoded));

        // A strong ETag of the plain body no longer describes these bytes: make it weak (the content is the same).
        if (($etag = $response->headers->get('ETag')) && ! str_starts_with($etag, 'W/')) {
            $response->headers->set('ETag', 'W/' . $etag);
        }

        return $response;
    }

    private function acceptsGzip(Request $request): bool
    {
        foreach (explode(',', strtolower((string) $request->headers->get('Accept-Encoding'))) as $part) {
            [$coding, $q] = array_pad(array_map('trim', explode(';', $part, 2)), 2, null);

            if ($coding === 'gzip') {
                // "gzip;q=0" means "not gzip".
                return $q === null || ! preg_match('/^q=0(\.0*)?$/', $q);
            }
        }

        return false;
    }

    private function compressible(Response $response): bool
    {
        if ($response instanceof BinaryFileResponse || $response instanceof StreamedResponse
            || $response->headers->has('Content-Encoding') || $response->isEmpty() || $response->getStatusCode() === 206) {
            return false;
        }

        $type = strtolower((string) $response->headers->get('Content-Type'));

        foreach (['json', 'text/', 'xml', 'svg', 'javascript'] as $needle) {
            if (str_contains($type, $needle)) {
                return true;
            }
        }

        return false;
    }
}
