<?php

namespace App\Http\Controllers;

use App\Http\Services\Admin\Templates\Packages\PackageFiles;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Serves the sanitized files of a template package (`theme.css` and `assets/`) with the headers that make a hostile
 * file harmless even if the sanitizers missed something (W6-08, spec 5.2): a fixed Content-Type chosen by extension,
 * `nosniff`, a sandboxing CSP, CORS only for the app's origins, and a year of immutable cache. No cookies, no session
 * (the route sits outside the web and api groups). In production the files usually come from a CDN/bucket
 * (`TEMPLATE_PACKAGES_URL`) that must send the same headers; `templates:verify-packages --probe` checks that.
 */
class TemplateAssetController extends Controller
{
    /** Extensions that are ever served, and nothing else. */
    private const SERVED = ['css', 'svg', 'webp', 'png', 'jpg', 'jpeg', 'woff2'];

    public function __construct(private readonly PackageFiles $files) {}

    public function show(Request $request, string $path): Response
    {
        if (! self::validPath($path)) {
            abort(404);
        }
        $disk = $this->files->disk();
        if (! $disk->exists($path)) {
            abort(404);
        }

        $staging = str_starts_with($path, PackageFiles::STAGING . '/');
        $response = response((string) $disk->get($path), 200, self::headers($path, $request->headers->get('Origin'), $staging));

        return $response;
    }

    /** Only `<slug>/<semver>-<16 hex>/theme.css|assets/...` and the same under `_staging/<26 chars>/`. */
    public static function validPath(string $path): bool
    {
        if (strlen($path) > 300 || str_contains($path, '..') || str_contains($path, '//')) {
            return false;
        }
        $tail = '(?:theme\.css|assets\/[a-z0-9][a-z0-9._\/-]*)';
        $ok = preg_match('#^[a-z][a-z0-9-]{1,31}/\d{1,5}\.\d{1,5}\.\d{1,5}-[0-9a-f]{16}/' . $tail . '$#', $path)
            || preg_match('#^' . PackageFiles::STAGING . '/[0-9a-z]{26}/' . $tail . '$#', $path);

        return (bool) $ok && in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::SERVED, true);
    }

    /** @return array<string, string> */
    public static function headers(string $path, ?string $origin, bool $staging = false): array
    {
        $headers = [
            'Content-Type' => PackageFiles::mime($path),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => PackageFiles::CSP,
            'Cross-Origin-Resource-Policy' => 'cross-origin',
            'Cache-Control' => $staging ? 'private, no-store' : 'public, max-age=31536000, immutable',
            'Vary' => 'Origin',
        ];
        if ($origin !== null && in_array($origin, self::allowedOrigins(), true)) {
            $headers['Access-Control-Allow-Origin'] = $origin;
        }

        return $headers;
    }

    /** @return list<string> */
    public static function allowedOrigins(): array
    {
        return array_values(array_unique(array_map(fn ($o) => rtrim(trim((string) $o), '/'), (array) config('invitation.packages.allowed_origins'))));
    }
}
