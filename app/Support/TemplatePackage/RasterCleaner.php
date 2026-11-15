<?php

namespace App\Support\TemplatePackage;

/**
 * Re-encodes an uploaded raster (png, jpg, webp) from its decoded pixels. What is stored and served is the output of the
 * encoder, never the bytes of the author: no metadata (EXIF, GPS, text chunks, ICC), no trailing data, no polyglot
 * payload, and the decoders a guest's browser runs only ever see what our encoder wrote (W6-05).
 */
final class RasterCleaner
{
    /** @return string|null the clean bytes, or null when the image cannot be decoded or encoded */
    public static function clean(string $bytes, string $ext): ?string
    {
        $im = @imagecreatefromstring($bytes);
        if ($im === false) {
            return null;
        }
        try {
            if ($ext === 'jpg' || $ext === 'jpeg') {
                $im = self::orient($im, $bytes);
            }
            imagesavealpha($im, true);
            ob_start();
            $ok = match ($ext) {
                'png' => imagepng($im, null, 9),
                'jpg', 'jpeg' => imagejpeg($im, null, 90),
                'webp' => imagewebp($im, null, 90),
                default => false,
            };
            $out = (string) ob_get_clean();
        } finally {
            imagedestroy($im);
        }

        return $ok && $out !== '' ? $out : null;
    }

    /** The EXIF orientation is applied to the pixels before the metadata is dropped, so the picture keeps looking right. */
    private static function orient(\GdImage $im, string $bytes): \GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $im;
        }
        $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($bytes));
        $angle = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180, 6 => -90, 8 => 90, default => 0,
        };
        if ($angle !== 0 && ($rotated = imagerotate($im, $angle, 0)) !== false) {
            imagedestroy($im);

            return $rotated;
        }

        return $im;
    }
}
