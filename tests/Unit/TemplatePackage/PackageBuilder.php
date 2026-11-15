<?php

namespace Tests\Unit\TemplatePackage;

use App\Support\TemplatePackage\TemplatePackageValidator;

/**
 * Test helper: loads the good fixture package, lets a test change / remove / add files, and writes a .zip. The raw
 * writer builds archives libzip would refuse to create (hostile names, symlinks, lying sizes), so the reader is tested
 * against the bytes an attacker would really send.
 */
class PackageBuilder
{
    /** @var array<string, string> */
    public array $files = [];

    /** @var array<string, array{declared?: int, mode?: int, flags?: int, raw?: string}> */
    public array $meta = [];

    /** @var list<string> */
    private static array $temp = [];

    public static function good(): self
    {
        $b = new self;
        $root = realpath(__DIR__ . '/../../Fixtures/TemplatePackage/good');
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $b->files[str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1))] = file_get_contents($file->getPathname());
        }
        ksort($b->files);

        return $b;
    }

    /** About 1 MB and 100+ files: the worst package the contract allows to be valid. */
    public static function heavy(): self
    {
        mt_srand(5);
        $b = self::good();
        for ($i = 0; $i < 52; $i++) {
            $d = '';
            for ($k = 0; $k < 90; $k++) {
                $d .= sprintf('M%d %d C %d %d, %d %d, %d %d ', mt_rand(0, 100), mt_rand(0, 100), mt_rand(0, 100), mt_rand(0, 100), mt_rand(0, 100), mt_rand(0, 100), mt_rand(0, 100), mt_rand(0, 100));
            }
            $b->asset("assets/img/leaf{$i}.svg", '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><g fill="#566f52" opacity=".8"><path d="' . $d . 'Z"/></g></svg>');
        }
        for ($i = 0; $i < 30; $i++) {
            $im = imagecreatetruecolor(180, 180);
            for ($x = 0; $x < 180; $x += 2) {
                for ($y = 0; $y < 180; $y += 2) {
                    imagefilledrectangle($im, $x, $y, $x + 1, $y + 1, imagecolorallocate($im, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
                }
            }
            ob_start();
            imagewebp($im, null, 60);
            $b->asset("assets/img/wash{$i}.webp", (string) ob_get_clean());
        }
        for ($i = 0; $i < 4; $i++) {
            $body = random_bytes(40_000);
            $b->asset("assets/fonts/f{$i}.woff2", 'wOF2' . "\x00\x01\x00\x00" . pack('N', 12 + strlen($body)) . $body);
        }
        $css = '';
        for ($i = 0; $i < 1200; $i++) {
            $css .= ".pkg-b{$i} .pkg-c{$i}:hover{color:#" . dechex(mt_rand(0x100000, 0xFFFFFF)) . ";margin:{$i}px auto;transition:opacity .3s ease-out}\n";
        }
        $b->files['theme.css'] = $css;
        for ($i = 0; $i < 12; $i++) {
            $b->files["sections/{$i}.html"] = '';
        }
        unset($b->files['sections/0.html']);
        $html = '<div class="pkg-w">' . str_repeat('<div class="pkg-card"><h2 class="pkg-t">{{ section.title }}</h2><p>{{ event.name|upper }} y {{ guest.first_name }}</p><img src="assets/img/wash0.webp" alt="" width="150" height="150"><a href="#rsvp">ir</a></div>', 60) . '</div>';
        foreach (['itinerary', 'locations', 'dress_code', 'padrinos', 'gallery', 'music', 'countdown', 'save_the_date'] as $type) {
            $b->files["sections/{$type}.html"] = $html;
        }
        foreach (array_keys($b->files) as $path) {
            if (preg_match('#^sections/\d+\.html$#', $path)) {
                unset($b->files[$path]);
            }
        }

        return $b;
    }

    public function with(string $path, string $content): self
    {
        $this->files[$path] = $content;

        return $this;
    }

    public function without(string $path): self
    {
        unset($this->files[$path]);

        return $this;
    }

    /** Edits a JSON file with a callback receiving and returning the decoded array. */
    public function json(string $path, callable $edit): self
    {
        $data = json_decode($this->files[$path], true);
        $this->files[$path] = json_encode($edit($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        return $this;
    }

    /** Adds an asset together with its manifest credit so only the thing under test fails. */
    public function asset(string $path, string $content): self
    {
        $this->files[$path] = $content;

        return $this->json('manifest.json', function (array $m) use ($path) {
            $m['assetsCredits'][] = ['path' => $path, 'title' => 'x', 'author' => 'x', 'source' => 'original', 'license' => 'original-work'];

            return $m;
        });
    }

    /** Writes the package with the raw writer and returns the path. */
    public function zip(): string
    {
        $base = tempnam(sys_get_temp_dir(), 'pkg');
        $path = $base . '.zip';
        self::$temp[] = $path;
        self::$temp[] = $base;
        file_put_contents($path, self::raw($this->files, $this->meta));

        return $path;
    }

    public function validate(array $installed = []): array
    {
        return (new TemplatePackageValidator)->validate($this->zip(), $installed);
    }

    /** @return list<string> error codes of a validation result */
    public static function errors(array $result): array
    {
        return array_values(array_unique(array_column($result['report']['errors'], 'code')));
    }

    public static function warnings(array $result): array
    {
        return array_values(array_unique(array_column($result['report']['warnings'], 'code')));
    }

    public static function cleanup(): void
    {
        foreach (self::$temp as $p) {
            @unlink($p);
        }
        self::$temp = [];
    }

    /**
     * Minimal zip writer (deflate). `meta[name]` may set `declared` (size written in the headers, to lie), `mode` (unix
     * mode in the external attributes, e.g. 0120777 for a symlink), `flags` (general purpose bits, 1 = encrypted) and
     * `raw` (compressed bytes used instead of deflating the content).
     *
     * @param  array<string, string>  $files
     * @param  array<string, array>  $meta
     */
    public static function raw(array $files, array $meta = []): string
    {
        $out = '';
        $central = '';
        $count = 0;
        foreach ($files as $name => $content) {
            $m = $meta[$name] ?? [];
            $compressed = $m['raw'] ?? gzdeflate($content, 6);
            $usize = $m['declared'] ?? strlen($content);
            $crc = crc32($content);
            $flags = $m['flags'] ?? 0;
            $offset = strlen($out);
            $out .= pack('VvvvvvVVVvv', 0x04034B50, 20, $flags, 8, 0, 0x21, $crc, strlen($compressed), $usize, strlen($name), 0) . $name . $compressed;
            $mode = $m['mode'] ?? (str_ends_with($name, '/') ? 040755 : 0100644);
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014B50, (3 << 8) | 20, 20, $flags, 8, 0, 0x21, $crc, strlen($compressed), $usize, strlen($name), 0, 0, 0, 0, $mode << 16, $offset) . $name;
            $count++;
        }
        $end = pack('VvvvvVVv', 0x06054B50, 0, 0, $count, $count, strlen($central), strlen($out), 0);

        return $out . $central . $end;
    }
}
