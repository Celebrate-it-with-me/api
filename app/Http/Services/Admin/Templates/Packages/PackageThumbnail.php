<?php

namespace App\Http\Services\Admin\Templates\Packages;

use App\Models\InvitationTemplate;
use App\Models\TemplatePackage;
use Illuminate\Support\Facades\Storage;

/**
 * The thumbnail of a package in the organizer's template selector (D-5): its `preview/mobile.png` (390x844 or twice that)
 * becomes a light WebP of 390x844 on the public package disk, under an immutable name. The original capture is never
 * served. A package without the capture simply has no thumbnail (the selector shows a placeholder).
 */
class PackageThumbnail
{
    public const WIDTH = 390;

    public const HEIGHT = 844;

    /** Staged thumbnails wait here (private) from the upload until the package is installed or the upload is discarded. */
    public const STAGING = 'template-staging-thumbs';

    public function __construct(private readonly PackageFiles $files) {}

    /** Where the thumbnail of a package lives on the package disk: it only changes when the content does. */
    public static function pathFor(string $slug, string $semver, string $contentHash): string
    {
        return "previews/{$slug}-{$semver}-" . substr($contentHash, 0, 16) . '.webp';
    }

    /** @return string|null WebP bytes, or null when the capture cannot be decoded */
    public function encode(string $png): ?string
    {
        $im = @imagecreatefromstring($png);
        if ($im === false) {
            return null;
        }
        try {
            if (imagesx($im) !== self::WIDTH) {
                $scaled = imagescale($im, self::WIDTH, self::HEIGHT, IMG_BICUBIC);
                if ($scaled === false) {
                    return null;
                }
                imagedestroy($im);
                $im = $scaled;
            }
            ob_start();
            $ok = imagewebp($im, null, 72);
            $out = (string) ob_get_clean();
        } finally {
            imagedestroy($im);
        }

        return $ok && $out !== '' ? $out : null;
    }

    public function stage(string $token, ?string $png): void
    {
        $webp = $png === null ? null : $this->encode($png);
        if ($webp !== null) {
            Storage::disk(config('invitation.packages.staging_disk'))->put(self::STAGING . "/{$token}.webp", $webp);
        }
    }

    public function discardStaged(string $token): void
    {
        Storage::disk(config('invitation.packages.staging_disk'))->delete(self::STAGING . "/{$token}.webp");
    }

    /** Publishes the staged thumbnail of an install. @return string|null its path on the package disk, null when there is none */
    public function publishStaged(string $token, string $slug, string $semver, string $contentHash): ?string
    {
        $local = Storage::disk(config('invitation.packages.staging_disk'));
        $staged = self::STAGING . "/{$token}.webp";
        if (! $local->exists($staged)) {
            return null;
        }

        return $this->publish($slug, $semver, $contentHash, (string) $local->get($staged));
    }

    public function publish(string $slug, string $semver, string $contentHash, string $webp): string
    {
        $path = self::pathFor($slug, $semver, $contentHash);
        $this->files->disk()->put($path, $webp, [
            'visibility' => 'public',
            'ContentType' => 'image/webp',
            'CacheControl' => 'public, max-age=31536000, immutable',
            'ContentDisposition' => 'inline',
        ]);

        return $path;
    }

    public function delete(?string $path): void
    {
        if ($path !== null) {
            $this->files->disk()->delete($path);
        }
    }

    /** The thumbnail path of an installed package when its file exists. */
    public function existing(TemplatePackage $package): ?string
    {
        $path = self::pathFor($package->slug, $package->semver, $package->content_hash);

        return $this->files->disk()->exists($path) ? $path : null;
    }

    /** Points every template of a package to its thumbnail. @return int templates updated */
    public function link(TemplatePackage $package, bool $onlyEmpty = false): int
    {
        $path = $this->existing($package);
        if ($path === null) {
            return 0;
        }
        $templateIds = $package->versions()->pluck('template_id')->unique();

        return InvitationTemplate::query()->whereIn('id', $templateIds)
            ->when($onlyEmpty, fn ($q) => $q->whereNull('preview_image'))
            ->where(fn ($q) => $q->whereNull('preview_image')->orWhere('preview_image', '!=', $path))
            ->update(['preview_image' => $path]);
    }
}
