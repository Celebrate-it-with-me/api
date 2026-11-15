<?php

namespace App\Support\TemplatePackage;

/**
 * Prototype validator of an uploaded template package (.zip). Reads the archive safely, validates the JSON documents,
 * sanitizes CSS, SVG and HTML templates, cross-checks references, licenses and budgets, and returns the report of spec
 * section 7.2 plus the sanitized artifacts (the only thing that may ever be stored or served).
 */
class TemplatePackageValidator
{
    private const HEX = '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/';

    private const COLOR_KEYS = ['surface', 'surfaceAlt', 'ink', 'inkSoft', 'accent', 'primary'];

    private const REQUIRED = ['manifest.json', 'tokens.json', 'sections.json', 'editable.json', 'LICENSES.md'];

    public function __construct(private readonly Limits $limits = new Limits) {}

    /**
     * @param  array<string, string>  $installedVersions  slug => highest installed semver, to enforce version_not_greater
     * @return array{valid: bool, report: array, artifacts: array{css: ?string, svg: array<string, string>, ast: array<string, array>, files: array<string, array{size: int, sha256: string}>}}
     */
    public function validate(string $zipPath, array $installedVersions = []): array
    {
        $report = new Report;
        $artifacts = ['css' => null, 'svg' => [], 'ast' => [], 'files' => []];
        $started = microtime(true);

        $files = (new PackageArchive($this->limits))->read($zipPath, $report);
        if ($files !== null) {
            $this->validateFiles($files, $report, $artifacts, $installedVersions);
        }

        $report->stats['elapsedMs'] = (int) round((microtime(true) - $started) * 1000);
        $report->stats['peakMemoryMb'] = round(memory_get_peak_usage(true) / 1048576, 1);

        return ['valid' => $report->valid(), 'report' => $report->toArray(), 'artifacts' => $artifacts];
    }

    /** @param array<string, string> $files */
    private function validateFiles(array $files, Report $report, array &$artifacts, array $installedVersions): void
    {
        foreach (self::REQUIRED as $required) {
            if (! isset($files[$required])) {
                $code = match ($required) {
                    'manifest.json' => 'manifest_missing',
                    'tokens.json' => 'tokens_invalid',
                    'sections.json' => 'sections_invalid',
                    'editable.json' => 'editable_invalid',
                    default => 'license_missing',
                };
                $report->error($required, $code, 'Archivo obligatorio ausente.');
            }
        }

        $json = [];
        foreach (['manifest.json', 'tokens.json', 'sections.json', 'editable.json'] as $name) {
            if (isset($files[$name])) {
                $json[$name] = $this->decode($files[$name], $name, $report);
            }
        }

        $manifest = is_array($json['manifest.json'] ?? null) ? $json['manifest.json'] : null;
        $slug = $manifest['slug'] ?? null;
        $slugOk = is_string($slug) && preg_match('/^[a-z][a-z0-9-]{1,31}$/', $slug);

        if ($manifest !== null) {
            $this->manifest($manifest, $report, $installedVersions);
            $report->manifest = array_intersect_key($manifest, array_flip(['slug', 'version', 'level', 'visibility', 'tier']));
        }

        foreach (array_keys($files) as $path) {
            if (! preg_match('/^(manifest|tokens|sections|editable|slots)\.json$|^(theme\.css|layout\.html|LICENSES\.md|README\.txt)$|^sections\/[a-z_]+\.html$|^assets\/.+|^preview\/(mobile\.png|desktop\.png|sample\.json)$/', $path)) {
                $report->error($path, 'unexpected_file', 'Archivo fuera de la estructura del contrato.');
                unset($files[$path]);
            }
        }

        $assets = array_values(array_filter(array_keys($files), fn (string $p) => str_starts_with($p, 'assets/')));
        $this->assets($files, $assets, $manifest, $report, $artifacts);

        if (is_array($json['tokens.json'] ?? null)) {
            $families = $this->tokens($json['tokens.json'], $files, $report);
        }
        if (is_array($json['sections.json'] ?? null)) {
            $this->sectionsJson($json['sections.json'], $manifest, $report);
        }
        if (is_array($json['editable.json'] ?? null)) {
            $this->editable($json['editable.json'], $report);
        }
        $this->previews($files, $report);

        // HTML first: its AST tells the CSS sanitizer which classes wrap system blocks.
        $classKinds = [];
        $usedAssets = [];
        $seenIds = [];
        foreach ($files as $path => $bytes) {
            if (! str_ends_with($path, '.html')) {
                continue;
            }
            $kind = $path === 'layout.html' ? 'layout' : 'section';
            $type = null;
            if ($kind === 'section') {
                if (! preg_match('/^sections\/([a-z_]+)\.html$/', $path, $m)) {
                    $report->error($path, 'ext_not_allowed', 'Las plantillas HTML van en sections/<tipo>.html o layout.html.');

                    continue;
                }
                $type = str_replace('-', '_', $m[1]);
                if (! in_array($type, BlockCatalog::SECTION_TYPES, true)) {
                    $report->error($path, 'sections_invalid', 'Tipo de sección desconocido.', null, $type);

                    continue;
                }
            }
            $built = (new HtmlAstBuilder)->build($bytes, $path, $kind, $type, $assets, $report);
            if ($built !== null) {
                $artifacts['ast'][$path] = $built['ast'];
                $classKinds = $this->mergeKinds($classKinds, $built['classKinds']);
                array_push($usedAssets, ...$built['assets']);
                foreach ($built['ids'] as $id) {
                    if (isset($seenIds[$id])) {
                        $report->error($path, 'html_duplicate_id', 'Id repetido en otra plantilla del paquete (todas viven en la misma página).', null, $id);
                    }
                    $seenIds[$id] = true;
                }
            }
        }

        if (isset($files['theme.css']) && $slugOk) {
            $sanitizer = new CssSanitizer($this->limits);
            $css = $sanitizer->sanitize($files['theme.css'], $slug, 'theme.css', $report, $assets, $families ?? [], $classKinds);
            $artifacts['css'] = $css;
            array_push($usedAssets, ...$sanitizer->usedAssets());
        } elseif (isset($files['theme.css'])) {
            $report->error('theme.css', 'slug_invalid', 'No se puede acotar el CSS sin un slug válido.');
        } else {
            $report->warning('theme.css', 'empty_css', 'Sin theme.css: solo se aplican los tokens.');
        }

        $this->crossChecks($files, $assets, array_unique($usedAssets), $manifest, $report, $artifacts);
    }

    private function decode(string $bytes, string $name, Report $report): mixed
    {
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            $bytes = substr($bytes, 3);
        }
        if (! mb_check_encoding($bytes, 'UTF-8') || preg_match('/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $bytes)) {
            $report->error($name, $this->jsonCode($name), 'JSON no UTF-8 o con caracteres bidireccionales de control.');

            return null;
        }
        try {
            $value = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $report->error($name, $this->jsonCode($name), 'JSON inválido: ' . $e->getMessage());

            return null;
        }
        if (! is_array($value)) {
            $report->error($name, $this->jsonCode($name), 'Se esperaba un objeto o lista JSON.');

            return null;
        }

        return $value;
    }

    private function jsonCode(string $name): string
    {
        return match ($name) {
            'manifest.json' => 'manifest_invalid',
            'tokens.json' => 'tokens_invalid',
            'sections.json' => 'sections_invalid',
            default => 'editable_invalid',
        };
    }

    private function manifest(array $m, Report $report, array $installed): void
    {
        $f = 'manifest.json';
        $bad = fn (string $field, string $code = 'manifest_invalid', string $msg = 'Valor inválido.') => $report->error($f, $code, "{$field}: {$msg}");

        if (($m['schema'] ?? null) !== 1) {
            $bad('schema');
        }
        $slug = $m['slug'] ?? null;
        if (! is_string($slug) || ! preg_match('/^[a-z][a-z0-9-]{1,31}$/', $slug)) {
            $bad('slug', 'slug_invalid');
        }
        foreach (['name' => 60, 'description' => 200] as $field => $max) {
            $v = $m[$field] ?? null;
            if (! is_array($v) || ! isset($v['es'], $v['en']) || ! is_string($v['es']) || ! is_string($v['en'])
                || mb_strlen($v['es']) > $max || mb_strlen($v['en']) > $max || $v['es'] === '' || $v['en'] === ''
                || preg_match('/[<>]/', $v['es'] . $v['en'])) {
                $bad($field);
            }
        }
        $version = $m['version'] ?? null;
        if (! is_string($version) || ! preg_match('/^(0|[1-9]\d{0,4})\.(0|[1-9]\d{0,4})\.(0|[1-9]\d{0,4})$/', $version)) {
            $bad('version', 'version_invalid');
        } elseif (is_string($slug) && isset($installed[$slug]) && version_compare($version, $installed[$slug], '<=')) {
            $bad('version', 'version_not_greater', "debe ser mayor que {$installed[$slug]}");
        }
        if (($m['level'] ?? null) !== 1) {
            $bad('level', 'level_not_allowed', 'un .zip solo puede ser de nivel 1');
        }
        $types = $m['eventTypes'] ?? null;
        if (! is_array($types) || $types === [] || array_diff($types, ['quinceanera', 'wedding']) !== []) {
            $bad('eventTypes');
        }
        if (! in_array($m['tier'] ?? null, ['free', 'premium'], true)) {
            $bad('tier');
        }
        if (! in_array($m['visibility'] ?? 'public', ['public', 'private'], true)) {
            $bad('visibility');
        }
        if (! is_int($m['engineMin'] ?? null) || $m['engineMin'] < 1) {
            $bad('engineMin');
        }
        $author = $m['author'] ?? null;
        if (! is_array($author) || ! is_string($author['name'] ?? null) || $author['name'] === '' || mb_strlen($author['name']) > 80
            || (isset($author['url']) && ! preg_match('/^https:\/\/[a-z0-9.-]+(\/\S*)?$/i', (string) $author['url']))) {
            $bad('author');
        }
        $license = $m['license'] ?? null;
        if (! is_array($license) || ! is_string($license['id'] ?? null) || ($license['document'] ?? null) !== 'LICENSES.md'
            || ! is_string($license['summary'] ?? null) || mb_strlen($license['summary']) > 300) {
            $report->error($f, 'license_missing', 'license.id, license.summary y license.document="LICENSES.md" son obligatorios.');
        }
        $marks = $m['thirdPartyMarks'] ?? null;
        if (! is_array($marks)) {
            $bad('thirdPartyMarks');
        } elseif ($marks !== []) {
            $report->warning($f, 'third_party_marks_declared', 'El paquete declara marcas de terceros: revisión humana obligatoria.');
            if (($m['visibility'] ?? 'public') === 'public') {
                $report->error($f, 'third_party_marks_public', 'Un paquete con marcas de terceros solo puede ser privado.');
            }
        }
        if (! is_array($m['assetsCredits'] ?? null)) {
            $bad('assetsCredits');
        }
    }

    /**
     * @param  array<string, string>  $files
     * @param  list<string>  $assets
     */
    private function assets(array $files, array $assets, ?array $manifest, Report $report, array &$artifacts): void
    {
        $finfoSvg = new SvgSanitizer;
        foreach ($assets as $path) {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $bytes = $files[$path];

            if (! in_array($ext, ['svg', 'webp', 'png', 'jpg', 'jpeg', 'avif', 'woff2'], true)) {
                $report->error($path, 'ext_not_allowed', 'assets/ solo admite svg, webp, png, jpg, avif y woff2.');

                continue;
            }
            if ($ext === 'svg') {
                $clean = $finfoSvg->sanitize($bytes, $path, $report);
                if ($clean !== null) {
                    $artifacts['svg'][$path] = $clean;
                }
            } elseif ($ext !== 'woff2') {
                $this->raster($bytes, $path, $report);
            }
        }
    }

    private function raster(string $bytes, string $path, Report $report): void
    {
        if (strlen($bytes) > $this->limits->rasterBytes) {
            $report->error($path, 'file_too_large', 'Imagen mayor de ' . intdiv($this->limits->rasterBytes, 1024) . ' KB.');

            return;
        }
        // Header only: nothing is ever decoded, so a pixel bomb (tiny file, huge canvas) costs nothing.
        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            $report->error($path, 'image_decode', 'No se pudo leer la cabecera de la imagen.');

            return;
        }
        [$w, $h] = $info;
        if ($w < 1 || $h < 1 || $w > $this->limits->imageSide || $h > $this->limits->imageSide || $w * $h > $this->limits->imagePixels) {
            $report->error($path, 'image_dimensions', 'Dimensiones fuera de límites.', null, "{$w}x{$h}");
        }
    }

    /**
     * @param  array<string, string>  $files
     * @return list<string> font families declared with own files
     */
    private function tokens(array $t, array $files, Report $report): array
    {
        $f = 'tokens.json';
        $families = [];

        $colors = $t['colors'] ?? null;
        if (! is_array($colors)) {
            $report->error($f, 'tokens_invalid', 'colors ausente.');
        } else {
            foreach (self::COLOR_KEYS as $key) {
                if (! is_string($colors[$key] ?? null) || ! preg_match(self::HEX, $colors[$key])) {
                    $report->error($f, 'color_invalid', "colors.{$key} debe ser hex.", null, (string) ($colors[$key] ?? ''));
                }
            }
            if (isset($colors['wash']) && (! is_string($colors['wash']) || ! preg_match(self::HEX, $colors['wash']))) {
                $report->error($f, 'color_invalid', 'colors.wash debe ser hex.');
            }
        }

        $fontFiles = 0;
        foreach (($t['fonts'] ?? []) as $role => $font) {
            if (! in_array($role, ['heading', 'body', 'script'], true) || ! is_array($font)) {
                $report->error($f, 'tokens_invalid', 'Rol de fuente desconocido.', null, (string) $role);

                continue;
            }
            $family = $font['family'] ?? null;
            if (! is_string($family) || ! preg_match('/^[\w -]{1,40}$/', $family)) {
                $report->error($f, 'tokens_invalid', "fonts.{$role}.family inválida.");

                continue;
            }
            $list = $font['files'] ?? [];
            if ($list === [] && ! in_array($family, ['Fraunces', 'Figtree'], true)) {
                $report->error($f, 'font_not_self_hosted', 'La fuente debe traer sus .woff2 en assets/fonts/.', null, $family);
            }
            foreach ($list as $entry) {
                $file = $entry['file'] ?? null;
                if (! is_string($file) || ! str_starts_with($file, 'assets/fonts/') || ! str_ends_with($file, '.woff2') || ! isset($files[$file])) {
                    $report->error($f, 'font_missing_file', 'Archivo de fuente ausente o fuera de assets/fonts/.', null, (string) $file);
                }
                $fontFiles++;
            }
            if ($list !== []) {
                $families[] = $family;
            }
        }
        if ($fontFiles > 8) {
            $report->error($f, 'tokens_invalid', 'Más de 8 archivos de fuente.');
        }

        $presets = $t['presets'] ?? [];
        if (is_array($presets) && (count($presets) > 8)) {
            $report->error($f, 'tokens_invalid', 'Más de 8 presets.');
        }

        return $families;
    }

    private function sectionsJson(array $s, ?array $manifest, Report $report): void
    {
        $f = 'sections.json';
        foreach (($manifest['eventTypes'] ?? []) as $type) {
            $list = $s[$type] ?? null;
            if (! is_array($list) || ! array_is_list($list) || $list === []) {
                $report->error($f, 'sections_invalid', "Falta la lista de secciones de {$type}.");

                continue;
            }
            $types = array_column($list, 'type');
            foreach (['cover', 'rsvp'] as $required) {
                if (! in_array($required, $types, true)) {
                    $report->error($f, 'sections_invalid', "{$type}: la sección {$required} es obligatoria.");
                }
            }
            if (($types[0] ?? null) !== 'cover') {
                $report->error($f, 'sections_invalid', "{$type}: la portada debe ir primera.");
            }
            foreach ($list as $i => $section) {
                if (! in_array($section['type'] ?? null, BlockCatalog::SECTION_TYPES, true)) {
                    $report->error($f, 'sections_invalid', "{$type}.{$i}.type desconocido.");
                }
            }
        }
    }

    private function editable(array $e, Report $report): void
    {
        $f = 'editable.json';
        $fields = $e['fields'] ?? null;
        if (! is_array($fields) || count($fields) > 40) {
            $report->error($f, 'editable_invalid', 'fields debe ser una lista de hasta 40 elementos.');

            return;
        }
        $seen = [];
        foreach ($fields as $i => $field) {
            $id = $field['id'] ?? null;
            if (! is_string($id) || ! preg_match('/^[a-z][a-z0-9_]{0,31}$/', $id) || isset($seen[$id])) {
                $report->error($f, 'editable_invalid', "fields.{$i}.id inválido o repetido.");
            }
            $seen[$id ?? ''] = true;
            if (! in_array($field['type'] ?? null, ['image', 'text', 'color', 'choice', 'toggle'], true)) {
                $report->error($f, 'editable_invalid', "fields.{$i}.type desconocido.");
            }
            if (! isset($field['bind']) || ! is_array($field['bind'])
                || array_intersect(array_keys($field['bind']), ['section', 'theme', 'option']) === []) {
                $report->error($f, 'editable_bind_unknown', "fields.{$i}.bind desconocido.");
            }
        }
    }

    /** @param array<string, string> $files */
    private function previews(array $files, Report $report): void
    {
        foreach (['preview/mobile.png' => [[390, 844], [780, 1688]], 'preview/desktop.png' => [[1366, 900], [2732, 1800]]] as $path => $sizes) {
            if (! isset($files[$path])) {
                $report->error($path, 'preview_missing', 'Captura obligatoria ausente.');

                continue;
            }
            $info = @getimagesizefromstring($files[$path]);
            if ($info === false || $info[2] !== IMAGETYPE_PNG) {
                $report->error($path, 'image_decode', 'La captura debe ser un PNG válido.');

                continue;
            }
            if (! in_array([$info[0], $info[1]], $sizes, true)) {
                $report->error($path, 'preview_size', 'Tamaño de captura incorrecto.', null, "{$info[0]}x{$info[1]}");
            }
        }
    }

    /**
     * @param  array<string, string>  $files
     * @param  list<string>  $assets
     * @param  array<int, string>  $used
     */
    private function crossChecks(array $files, array $assets, array $used, ?array $manifest, Report $report, array &$artifacts): void
    {
        // Licenses and credits: exactly one record per file of assets/.
        $credits = is_array($manifest['assetsCredits'] ?? null) ? $manifest['assetsCredits'] : [];
        $credited = [];
        foreach ($credits as $credit) {
            $path = is_array($credit) ? ($credit['path'] ?? null) : null;
            if (! is_string($path)) {
                $report->error('manifest.json', 'manifest_invalid', 'assetsCredits: entrada sin path.');

                continue;
            }
            foreach (['title', 'author', 'source', 'license'] as $k) {
                if (! is_string($credit[$k] ?? null) || $credit[$k] === '') {
                    $report->error('manifest.json', 'license_asset_missing', "assetsCredits.{$k} vacío.", null, $path);
                }
            }
            $credited[$path] = true;
            if (! in_array($path, $assets, true)) {
                $report->error('manifest.json', 'license_asset_unknown', 'Crédito de un archivo que no existe.', null, $path);
            }
        }
        foreach ($assets as $path) {
            if (! isset($credited[$path])) {
                $report->error($path, 'license_asset_missing', 'Falta su registro en assetsCredits.');
            }
            if (! in_array($path, $used, true) && ! str_starts_with($path, 'assets/fonts/')) {
                $report->warning($path, 'unused_file', 'Ningún HTML ni CSS lo referencia.');
            }
        }

        // Runtime budget: what a visitor can download = sanitized assets + served CSS (+ fonts).
        $runtime = strlen((string) $artifacts['css']);
        foreach ($assets as $path) {
            $runtime += isset($artifacts['svg'][$path]) ? strlen($artifacts['svg'][$path]) : strlen($files[$path]);
        }
        if ($runtime > $this->limits->runtimeMax) {
            $report->error('package.zip', 'package_too_large', 'El peso en ejecución supera 1 MB.', null, (string) $runtime);
        } elseif ($runtime > $this->limits->runtimeWarn) {
            $report->warning('package.zip', 'budget_exceeded', 'El peso en ejecución supera 350 KB.', null, (string) $runtime);
        }

        foreach ($files as $path => $bytes) {
            $artifacts['files'][$path] = ['size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
        }
        $report->stats += [
            'files' => count($files),
            'uncompressedBytes' => array_sum(array_map('strlen', $files)),
            'runtimeBytes' => $runtime,
        ];
    }

    /** @param array<string, string> $a @param array<string, string> $b */
    private function mergeKinds(array $a, array $b): array
    {
        foreach ($b as $k => $kind) {
            if (($a[$k] ?? '') !== 'system') {
                $a[$k] = $kind;
            }
        }

        return $a;
    }
}
