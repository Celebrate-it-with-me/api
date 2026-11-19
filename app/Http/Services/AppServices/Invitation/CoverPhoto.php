<?php

namespace App\Http\Services\AppServices\Invitation;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;

/**
 * The cover photo of an invitation: validated by its real bytes, oriented, stripped of every piece of metadata (the
 * photo of a minor must not carry her location), re-encoded as WebP (1440 px wide and a 480 px thumbnail) and stored on
 * the `event-media` disk. The original is not kept. The config only ever holds `upload:<path>`; the client never sends
 * a path, and a path is served only for the event whose folder it is in.
 */
final class CoverPhoto
{
    public const PREFIX = 'upload:';

    public const DISK = 'event-media';

    public const SHARE_WIDTH = 1200;

    public const SHARE_HEIGHT = 630;

    private const SHARE_MAX_BYTES = 300_000;

    private const PATH = '#^images/event/(\d+)/cover/([0-9a-z]{26})-(\d{2,5})x(\d{2,5})\.webp$#';

    private const TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(private readonly ImageManager $images) {}

    /** The stored path of an `upload:` value, only when it is in the folder of this event. */
    public static function pathFor(string $value, int $eventId): ?string
    {
        if (! str_starts_with($value, self::PREFIX)) {
            return null;
        }
        $path = substr($value, strlen(self::PREFIX));

        return preg_match(self::PATH, $path, $m) === 1 && (int) $m[1] === $eventId ? $path : null;
    }

    public static function thumbPath(string $path): string
    {
        return substr($path, 0, -strlen('.webp')) . '-thumb.webp';
    }

    /** The 1200x630 JPEG a link preview (WhatsApp, Facebook) shows; derived from the stored WebP. */
    public static function sharePath(string $path): string
    {
        return substr($path, 0, -strlen('.webp')) . '-share.jpg';
    }

    /** @return array{width: int, height: int}|null the size of the stored photo, kept in its name */
    public static function dimensions(string $path): ?array
    {
        return preg_match(self::PATH, $path, $m) === 1 ? ['width' => (int) $m[3], 'height' => (int) $m[4]] : null;
    }

    /**
     * @return array{path: string, width: int, height: int, bytes: int}
     *
     * @throws ValidationException `errors.photo[0]` = required | not_an_image | unsupported_type | too_large | too_small | too_many_pixels | corrupt
     */
    public function store(int $eventId, ?UploadedFile $file, int $minWidth = 0, ?string $focus = null): array
    {
        $max = (int) config('invitation.cover.max_bytes');

        if ($file === null) {
            $this->fail('required');
        }
        if ($file->getError() === UPLOAD_ERR_INI_SIZE || $file->getError() === UPLOAD_ERR_FORM_SIZE) {
            $this->fail('too_large');
        }
        if (! $file->isValid() || ($file->getSize() ?: 0) === 0) {
            $this->fail('required');
        }
        if ($file->getSize() > $max) {
            $this->fail('too_large');
        }

        $bytes = (string) file_get_contents($file->getRealPath());
        // The real type, from the bytes: neither the extension nor the type the browser claims.
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (! is_string($mime) || ! str_starts_with($mime, 'image/')) {
            $this->fail('not_an_image');
        }
        if (! in_array($mime, self::TYPES, true)) {
            $this->fail('unsupported_type');
        }

        $info = @getimagesizefromstring($bytes);
        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            $this->fail('corrupt');
        }
        // Checked from the header, before anything is decoded: a small file can hold a huge picture.
        if (max($info[0], $info[1]) > (int) config('invitation.cover.max_side') || (int) config('invitation.cover.max_pixels') < $info[0] * $info[1]) {
            $this->fail('too_many_pixels');
        }
        if ($minWidth > 0 && $info[0] < $minWidth) {
            $this->fail('too_small');
        }

        // Decoding needs about four bytes per pixel, several times over.
        $needed = (int) ($info[0] * $info[1] * 4 * 3 / 1048576) + 64;
        $limit = ini_get('memory_limit');
        if ($limit !== '-1' && $this->megabytes((string) $limit) < $needed) {
            @ini_set('memory_limit', $needed . 'M');
        }

        try {
            $image = $this->images->decodeBinary($bytes)->orient();
            $cover = (clone $image)->scaleDown(width: (int) config('invitation.cover.width'));
            $thumb = (clone $image)->scaleDown(width: (int) config('invitation.cover.thumb_width'));
            $quality = (int) config('invitation.cover.quality');
            $coverBytes = (string) $cover->encode(new WebpEncoder(quality: $quality));
            $thumbBytes = (string) $thumb->encode(new WebpEncoder(quality: $quality));
            $width = $cover->width();
            $height = $cover->height();
        } catch (\Throwable) {
            $this->fail('corrupt');
        }

        // A new name every time: nothing is overwritten, an old cache cannot show the previous photo, and names cannot be guessed.
        $path = sprintf('images/event/%d/cover/%s-%dx%d.webp', $eventId, strtolower((string) Str::ulid()), $width, $height);
        $disk = Storage::disk(self::DISK);
        $options = ['visibility' => 'public', 'ContentType' => 'image/webp', 'CacheControl' => 'public, max-age=31536000, immutable'];
        $disk->put($path, $coverBytes, $options);
        try {
            $disk->put(self::thumbPath($path), $thumbBytes, $options);
        } catch (\Throwable $e) {
            $disk->delete($path);
            throw $e;
        }
        // The preview image is a convenience: when it cannot be made now, it is made on the first request that needs it.
        $this->makeShare($path, $focus);

        return ['path' => $path, 'width' => $width, 'height' => $height, 'bytes' => strlen($coverBytes)];
    }

    /** Removes both files of a stored photo. Idempotent. */
    public function delete(?string $path): void
    {
        if ($path === null || preg_match(self::PATH, $path) !== 1) {
            return;
        }
        Storage::disk(self::DISK)->delete([$path, self::thumbPath($path), self::sharePath($path)]);
    }

    /**
     * Makes the link-preview image of a stored photo: 1200x630 (1.91:1) JPEG, cropped around `$focus` ("50% 30%", the
     * centre when none), well under 300 KB, with no metadata. Idempotent: an existing one is kept unless `$force`.
     *
     * @return bool whether the derived image exists afterwards
     */
    public function makeShare(string $path, ?string $focus = null, bool $force = false): bool
    {
        if (preg_match(self::PATH, $path) !== 1) {
            return false;
        }
        $disk = Storage::disk(self::DISK);
        $target = self::sharePath($path);

        try {
            if (! $force && $disk->exists($target)) {
                return true;
            }
            $source = $disk->get($path);
            if (! is_string($source) || $source === '') {
                return false;
            }
            $w = self::SHARE_WIDTH;
            $h = self::SHARE_HEIGHT;
            $image = $this->images->decodeBinary($source)->fillTransparentAreas('ffffff');
            $factor = max($w / $image->width(), $h / $image->height());
            $scaledW = max($w, (int) ceil($image->width() * $factor));
            $scaledH = max($h, (int) ceil($image->height() * $factor));
            [$fx, $fy] = $this->focusPoint($focus);
            $image->resize($scaledW, $scaledH)->crop(
                $w,
                $h,
                (int) max(0, min($scaledW - $w, round($fx * $scaledW - $w / 2))),
                (int) max(0, min($scaledH - $h, round($fy * $scaledH - $h / 2))),
            );
            $quality = 82;
            do {
                $jpeg = (string) $image->encode(new JpegEncoder(quality: $quality));
                $quality -= 10;
            } while (strlen($jpeg) > self::SHARE_MAX_BYTES && $quality >= 40);

            $disk->put($target, $jpeg, ['visibility' => 'public', 'ContentType' => 'image/jpeg', 'CacheControl' => 'public, max-age=31536000, immutable']);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** @return array{0: float, 1: float} the focus as fractions of the photo */
    private function focusPoint(?string $focus): array
    {
        if ($focus !== null && preg_match('/^(\d{1,3})% (\d{1,3})%$/', $focus, $m) === 1) {
            return [min(100, (int) $m[1]) / 100, min(100, (int) $m[2]) / 100];
        }

        return [0.5, 0.5];
    }

    /** @return array{url: string, thumbUrl: string} */
    public function urls(string $path): array
    {
        $disk = Storage::disk(self::DISK);

        return ['url' => $disk->url($path), 'thumbUrl' => $disk->url(self::thumbPath($path))];
    }

    private function fail(string $code): never
    {
        throw ValidationException::withMessages(['photo' => [$code]]);
    }

    private function megabytes(string $value): int
    {
        $n = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $n * 1024,
            'k' => intdiv($n, 1024),
            'm' => $n,
            default => intdiv($n, 1048576),
        };
    }
}
