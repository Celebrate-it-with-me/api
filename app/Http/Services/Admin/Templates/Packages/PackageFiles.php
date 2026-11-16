<?php

namespace App\Http\Services\Admin\Templates\Packages;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * The `template-packages` disk: sanitized `theme.css` and `assets/` of every installed package, under immutable paths
 * `<slug>/<version>-<hash8>/` (and `_staging/<token>/` while an upload waits to be installed).
 */
class PackageFiles
{
    public const STAGING = '_staging';

    /** Policy of every served file: inert even if opened directly (no script, no framing, no network). */
    public const CSP = "default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox";

    public function disk(): Filesystem
    {
        return Storage::disk((string) config('invitation.packages.disk'));
    }

    public static function mime(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'json' => 'application/json',
            'css' => 'text/css; charset=utf-8',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'avif' => 'image/avif',
            'woff2' => 'font/woff2',
            'html' => 'text/html; charset=utf-8',
            'md' => 'text/markdown; charset=utf-8',
            default => 'text/plain; charset=utf-8',
        };
    }

    /** @param array<string, string> $files relative path => bytes */
    public function write(string $folder, array $files): void
    {
        $disk = $this->disk();
        foreach ($files as $path => $bytes) {
            // Content type and a year of immutable cache: the path changes whenever the content does.
            $disk->put("{$folder}/{$path}", $bytes, [
                'visibility' => 'public',
                'ContentType' => self::mime($path),
                'CacheControl' => 'public, max-age=31536000, immutable',
                'ContentDisposition' => 'inline',
            ]);
        }
    }

    /** Copies a whole folder inside the disk (a staged upload to its final place). */
    public function copyFolder(string $from, string $to): void
    {
        $disk = $this->disk();
        foreach ($disk->allFiles($from) as $file) {
            $disk->copy($file, $to . substr($file, strlen($from)));
        }
    }

    public function delete(string $folder): void
    {
        $this->disk()->deleteDirectory($folder);
    }

    public function exists(string $folder): bool
    {
        return $this->disk()->exists($folder . '/theme.css');
    }

    /** Base URL of the files; null when it is not configured (only local and testing may fall back to the API itself). */
    public function baseUrl(): ?string
    {
        $url = config('filesystems.disks.' . config('invitation.packages.disk') . '.url');

        return is_string($url) && trim($url) !== '' ? rtrim($url, '/') : null;
    }

    public function url(string $path): string
    {
        $base = $this->baseUrl();
        if ($base === null) {
            throw new MissingPackagesUrl('TEMPLATE_PACKAGES_URL is not configured: package files have no public origin.');
        }

        return $base . '/' . ltrim($path, '/');
    }

    /**
     * Recomputes the sha256 of every published file of a folder against what was recorded when it was built.
     *
     * @param  list<array{path: string, size: int, sha256: string, published: bool}>  $expected
     * @return list<string> human readable differences (empty when the folder is exactly what was built)
     */
    public function differences(string $folder, array $expected): array
    {
        $disk = $this->disk();
        $problems = [];
        $known = [];
        foreach ($expected as $file) {
            if (! ($file['published'] ?? false)) {
                continue;
            }
            $known["{$folder}/{$file['path']}"] = true;
            $path = "{$folder}/{$file['path']}";
            if (! $disk->exists($path)) {
                $problems[] = "falta {$file['path']}";
            } elseif (hash('sha256', (string) $disk->get($path)) !== $file['sha256']) {
                $problems[] = "difiere {$file['path']}";
            }
        }
        foreach ($disk->allFiles($folder) as $found) {
            if (! isset($known[$found])) {
                $problems[] = 'sobra ' . substr($found, strlen($folder) + 1);
            }
        }

        return $problems;
    }
}
