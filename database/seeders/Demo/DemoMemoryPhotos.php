<?php

namespace Database\Seeders\Demo;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Sample "sweet memories" of a party for the demo event: three small illustrations drawn with GD (balloons, confetti,
 * a cake), so nothing is downloaded and no real person appears. They go through the same disk and folders a real upload uses.
 */
class DemoMemoryPhotos
{
    private const DISK = 'sweet-memories';

    /** title, description, year, background top, background bottom */
    private const MEMORIES = [
        ['Mi primer cumpleaños', 'La torta, los globos y toda la familia reunida.', '2013', [255, 214, 224], [255, 244, 230]],
        ['Fiesta de disfraces', 'El año que todas las primas vinieron de princesas.', '2018', [214, 230, 255], [255, 236, 245]],
        ['Cumpleaños número 12', 'Piñata, confeti y mucha música con las amigas.', '2024', [255, 233, 196], [255, 210, 222]],
    ];

    public function seed(int $eventId, \DateTimeInterface $now): void
    {
        if (! function_exists('imagecreatetruecolor') || DB::table('sweet_memories_images')->where('event_id', $eventId)->exists()) {
            return;
        }

        $disk = Storage::disk(self::DISK);

        foreach (self::MEMORIES as $i => [$title, $description, $year, $top, $bottom]) {
            $name = "demo-party-{$year}.jpg";
            $path = "images/event/{$eventId}/memories-images/{$name}";
            $thumbPath = "images/event/{$eventId}/memories-thumbnails/thumbnail_{$name}";

            $jpeg = $this->draw(800, 600, $top, $bottom, $i + 1);
            $disk->put($path, $jpeg);
            $disk->put($thumbPath, $this->draw(150, 150, $top, $bottom, $i + 1));

            DB::table('sweet_memories_images')->insert([
                'event_id' => $eventId, 'image_path' => $path, 'image_name' => $name, 'image_original_name' => $name,
                'image_size' => strlen($jpeg), 'thumbnail_path' => $thumbPath, 'thumbnail_name' => "thumbnail_{$name}",
                'title' => $title, 'description' => $description, 'year' => $year, 'visible' => true, 'order' => $i,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    /** @param  array{0:int,1:int,2:int}  $top */
    private function draw(int $w, int $h, array $top, array $bottom, int $seed): string
    {
        $img = imagecreatetruecolor($w, $h);

        for ($y = 0; $y < $h; $y++) {
            $t = $y / $h;
            $c = imagecolorallocate($img, (int) ($top[0] + ($bottom[0] - $top[0]) * $t), (int) ($top[1] + ($bottom[1] - $top[1]) * $t), (int) ($top[2] + ($bottom[2] - $top[2]) * $t));
            imageline($img, 0, $y, $w, $y, $c);
        }

        mt_srand($seed * 7919);
        $palette = [[233, 30, 99], [255, 193, 7], [156, 39, 176], [3, 169, 244], [76, 175, 80], [255, 112, 67]];
        $col = fn (array $c, int $alpha = 0) => imagecolorallocatealpha($img, $c[0], $c[1], $c[2], $alpha);

        // Confetti.
        for ($n = 0; $n < 70; $n++) {
            imagefilledellipse($img, mt_rand(0, $w), mt_rand(0, $h), (int) ($w / 70), (int) ($w / 100), $col($palette[$n % 6], 40));
        }

        // Balloons with strings.
        for ($b = 0; $b < 5; $b++) {
            $x = (int) ($w * (0.12 + 0.19 * $b));
            $y = (int) ($h * (0.22 + 0.06 * (($b + $seed) % 3)));
            $r = (int) ($w * 0.07);
            imageline($img, $x, $y + $r, $x + mt_rand(-10, 10), (int) ($h * 0.62), $col([120, 110, 110]));
            imagefilledellipse($img, $x, $y, $r * 2, (int) ($r * 2.3), $col($palette[($b + $seed) % 6], 10));
            imagefilledellipse($img, $x - (int) ($r * 0.3), $y - (int) ($r * 0.4), (int) ($r * 0.5), (int) ($r * 0.7), $col([255, 255, 255], 70));
        }

        // A cake on a table.
        $cx = (int) ($w * 0.5);
        $base = (int) ($h * 0.92);
        imagefilledrectangle($img, 0, $base, $w, $h, $col([188, 143, 143]));
        imagefilledrectangle($img, $cx - (int) ($w * 0.17), $base - (int) ($h * 0.13), $cx + (int) ($w * 0.17), $base, $col([255, 245, 238]));
        imagefilledrectangle($img, $cx - (int) ($w * 0.12), $base - (int) ($h * 0.24), $cx + (int) ($w * 0.12), $base - (int) ($h * 0.13), $col([244, 143, 177]));
        imagefilledrectangle($img, $cx - 3, $base - (int) ($h * 0.32), $cx + 3, $base - (int) ($h * 0.24), $col([255, 255, 255]));
        imagefilledellipse($img, $cx, $base - (int) ($h * 0.34), 10, 16, $col([255, 160, 0]));

        ob_start();
        imagejpeg($img, null, 82);
        $jpeg = (string) ob_get_clean();
        imagedestroy($img);

        return $jpeg;
    }
}
