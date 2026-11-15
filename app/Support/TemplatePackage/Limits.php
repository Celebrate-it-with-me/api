<?php

namespace App\Support\TemplatePackage;

/** Numeric limits of the package contract (spec section 1.6). One place so the tests can tighten them. */
class Limits
{
    public int $zipBytes = 8_388_608;

    public int $uncompressedBytes = 12_582_912;

    public int $entries = 120;

    public int $hardEntries = 600;

    public int $depth = 4;

    public int $pathLength = 120;

    public int $ratio = 100;

    public int $jsonBytes = 65_536;

    public int $cssBytes = 204_800;

    public int $cssServedBytes = 266_240;

    public int $rasterBytes = 409_600;

    public int $svgBytes = 61_440;

    public int $woff2Bytes = 153_600;

    public int $htmlBytes = 65_536;

    public int $textBytes = 65_536;

    public int $previewBytes = 819_200;

    public int $imageSide = 4096;

    public int $imagePixels = 12_000_000;

    public float $cssParseSeconds = 5.0;

    public int $cssParseBytes = 100_663_296;

    public int $runtimeWarn = 358_400;

    public int $runtimeMax = 1_048_576;

    public int $firstFoldWarn = 184_320;

    public function capFor(string $ext): int
    {
        return match ($ext) {
            'json' => $this->jsonBytes,
            'css' => $this->cssBytes,
            'svg' => $this->svgBytes,
            'webp', 'png', 'jpg', 'jpeg', 'avif' => max($this->rasterBytes, $this->previewBytes),
            'woff2' => $this->woff2Bytes,
            'html' => $this->htmlBytes,
            default => $this->textBytes,
        };
    }
}
