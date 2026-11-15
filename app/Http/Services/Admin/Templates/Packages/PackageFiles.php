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

    public function url(string $path): string
    {
        return $this->disk()->url($path);
    }
}
