<?php

namespace App\Http\Services\Admin\Templates\Packages;

/**
 * Turns the result of the validator into what gets stored: the public files (served `theme.css` with the generated
 * `@font-face` rules, sanitized SVG, images and fonts), the AST bundle, the file list and the content hash that names
 * the immutable folder.
 */
final class PackageBuild
{
    /**
     * @param  array{valid: bool, report: array, artifacts: array}  $result  a valid result of TemplatePackageValidator
     * @return array{public: array<string, string>, ast: array{layout: ?array, sections: array<string, array>}, files: list<array{path: string, size: int, sha256: string, mime: string, published: bool}>, contentHash: string, json: array<string, array>}
     */
    public static function from(array $result): array
    {
        $a = $result['artifacts'];
        $json = $a['json'];

        $public = [];
        $css = self::fontFaces($json['tokens']) . (string) $a['css'];
        if ($css !== '') {
            $public['theme.css'] = $css;
        }
        foreach ($a['blobs'] as $path => $bytes) {
            $public[$path] = $bytes;
        }
        foreach ($a['svg'] as $path => $svg) {
            $public[$path] = $svg;
        }
        ksort($public);

        $ast = ['layout' => $a['ast']['layout.html'] ?? null, 'sections' => []];
        foreach ($a['ast'] as $path => $tree) {
            if (preg_match('#^sections/([a-z_]+)\.html$#', $path, $m)) {
                $ast['sections'][$m[1]] = $tree;
            }
        }
        ksort($ast['sections']);

        $files = [];
        foreach ($a['files'] as $path => $meta) {
            $published = isset($public[$path]);
            $files[] = [
                'path' => $path,
                'size' => $published ? strlen($public[$path]) : $meta['size'],
                'sha256' => $published ? hash('sha256', $public[$path]) : $meta['sha256'],
                'mime' => PackageFiles::mime($path),
                'published' => $published,
            ];
        }
        if (isset($public['theme.css']) && ! isset($a['files']['theme.css'])) {
            $files[] = ['path' => 'theme.css', 'size' => strlen($public['theme.css']), 'sha256' => hash('sha256', $public['theme.css']), 'mime' => PackageFiles::mime('theme.css'), 'published' => true];
        }
        usort($files, fn (array $x, array $y) => strcmp($x['path'], $y['path']));

        return [
            'public' => $public,
            'ast' => $ast,
            'files' => $files,
            'contentHash' => self::hash($public, $ast, $json),
            'json' => $json,
        ];
    }

    /** sha256 of the sanitized content: sorted (path, sha256) of every public file plus the stored documents. */
    private static function hash(array $public, array $ast, array $json): string
    {
        $lines = [];
        foreach ($public as $path => $bytes) {
            $lines[] = "{$path} " . hash('sha256', $bytes);
        }
        foreach (['@ast' => $ast] + array_combine(array_map(fn ($k) => "@{$k}", array_keys($json)), array_values($json)) as $name => $doc) {
            $lines[] = "{$name} " . hash('sha256', (string) json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));
        }
        sort($lines);

        return hash('sha256', implode("\n", $lines));
    }

    /**
     * The theme of a version of a package (the shape TemplateSchema and the public payload read): the package slug as
     * theme name, the font family per role and the presets with the colors of each.
     *
     * @return array<string, mixed>
     */
    public static function theme(string $slug, array $tokens): array
    {
        return [
            'theme' => $slug,
            'fonts' => array_map(fn (array $font) => $font['family'], $tokens['fonts']),
            'preset' => $tokens['preset'],
            'presets' => $tokens['presets'],
        ];
    }

    /** `@font-face` rules the server writes (a package cannot declare them): swap, woff2, relative to theme.css. */
    public static function fontFaces(array $tokens): string
    {
        $css = '';
        $seen = [];
        foreach (['heading', 'body', 'script'] as $role) {
            $font = $tokens['fonts'][$role] ?? null;
            foreach ($font['files'] ?? [] as $entry) {
                $key = $font['family'] . '|' . $entry['file'];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $css .= sprintf(
                    '@font-face{font-family:"%s";font-style:%s;font-weight:%d;font-display:swap;src:url(%s) format("woff2")}',
                    $font['family'],
                    $entry['style'],
                    $entry['weight'],
                    $entry['file']
                );
            }
        }

        return $css;
    }
}
