<?php

namespace App\Support\TemplatePackage;

use App\Http\Services\Admin\Templates\TemplateSchema;

/**
 * Validator of an uploaded template package (.zip), the single entry point of the installer, the admin upload and the
 * `templates:lint` command. Reads the archive safely, validates the JSON documents, sanitizes the HTML templates (into
 * an AST), the CSS and the SVG assets, cross-checks references, licenses and budgets, and returns the report of spec
 * section 7.2 plus the sanitized artifacts (the only thing that may ever be stored or served; the original bytes of
 * text files are never kept). Validation order matters: HTML first, because its AST tells the CSS sanitizer which
 * classes wrap system blocks.
 */
class TemplatePackageValidator
{
    private const HEX = '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/';

    private const COLOR_KEYS = ['surface', 'surfaceAlt', 'ink', 'inkSoft', 'accent', 'primary'];

    private const REQUIRED = ['manifest.json', 'tokens.json', 'sections.json', 'editable.json', 'LICENSES.md'];

    /** Slugs only the trusted console may install: the system's own theme and the prefixes of the engine's keyframes. */
    public const RESERVED_SLUGS = ['classic', 'inv', 'ui', 'pk', 'default', 'system', 'engine'];

    /** Families bundled with the SPA: usable without shipping a file. */
    private const ENGINE_FAMILIES = ['Fraunces', 'Figtree'];

    /** Families the engine still loads from Google (D-6): only the trusted console install may use them. */
    private const ENGINE_REMOTE_FAMILIES = ['Pinyon Script'];

    private ValidationContext $context;

    /** @var list<string> editable field ids of the package being validated (for the removed-field warning) */
    private array $currentEditableIds = [];

    public function __construct(private readonly Limits $limits = new Limits) {}

    /**
     * @param  ValidationContext|array<string, string>|null  $context  an array is read as `slug => highest installed semver`
     * @return array{valid: bool, report: array, artifacts: array{css: ?string, svg: array<string, string>, ast: array<string, array>, files: array<string, array{size: int, sha256: string}>, json: array<string, array>, blobs: array<string, string>}}
     */
    public function validate(string $zipPath, ValidationContext|array|null $context = null): array
    {
        $this->context = $context instanceof ValidationContext ? $context : new ValidationContext(installed: $context ?? []);
        $installedVersions = $this->context->installed;
        $report = new Report;
        $artifacts = ['css' => null, 'svg' => [], 'ast' => [], 'files' => [], 'json' => [], 'blobs' => []];
        $this->currentEditableIds = [];
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
        foreach (['manifest.json', 'tokens.json', 'sections.json', 'editable.json', 'strings.json'] as $name) {
            if (isset($files[$name])) {
                $json[$name] = $this->decode($files[$name], $name, $report);
            }
        }

        $manifest = is_array($json['manifest.json'] ?? null) ? $json['manifest.json'] : null;
        $slug = $manifest['slug'] ?? null;
        $slugOk = is_string($slug) && preg_match('/^[a-z][a-z0-9-]{1,31}$/', $slug);

        if ($manifest !== null) {
            $this->manifest($manifest, $report);
            // The summary the admin shows before anything is installed: identity, licenses, credits, marks and presets.
            $report->manifest = array_intersect_key($manifest, array_flip(['slug', 'version', 'level', 'visibility', 'tier', 'eventTypes', 'license', 'assetsCredits', 'thirdPartyMarks']));
            $presets = is_array($json['tokens.json'] ?? null) ? ($json['tokens.json']['presets'] ?? null) : null;
            $report->manifest['presets'] = is_array($presets) ? $presets : (object) [];
        }

        foreach (array_keys($files) as $path) {
            if (! preg_match('/^(manifest|tokens|sections|editable|strings)\.json$|^(theme\.css|layout\.html|LICENSES\.md|README\.txt)$|^sections\/[a-z_]+\.html$|^assets\/.+|^preview\/(mobile\.png|desktop\.png|sample\.json)$/', $path)) {
                $report->error($path, 'unexpected_file', 'Archivo fuera de la estructura del contrato.');
                unset($files[$path]);
            }
        }

        $assets = array_values(array_filter(array_keys($files), fn (string $p) => str_starts_with($p, 'assets/')));
        $this->assets($files, $assets, $report, $artifacts);  // re-encodes the rasters into $files

        $families = [];
        if (is_array($json['tokens.json'] ?? null)) {
            $families = $this->tokens($json['tokens.json'], $files, $manifest, $report);
        }
        if (is_array($json['sections.json'] ?? null)) {
            $this->sectionsJson($json['sections.json'], $manifest, $report);
        }
        $stringKeys = [];
        if (is_array($json['strings.json'] ?? null)) {
            $stringKeys = $this->strings($json['strings.json'], $report);
        }
        if (is_array($json['editable.json'] ?? null)) {
            $this->editable($json['editable.json'], $manifest, $report);
        }
        $this->previews($files, $report);

        // HTML first: its AST tells the CSS sanitizer which classes wrap system blocks.
        $classKinds = [];
        $usedAssets = [];
        $seenIds = [];
        $firstFold = [];
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
            $built = (new HtmlAstBuilder)->withStrings($stringKeys)->build($bytes, $path, $kind, $type, $assets, $report);
            if ($built !== null) {
                $artifacts['ast'][$path] = $built['ast'];
                $classKinds = $this->mergeKinds($classKinds, $built['classKinds']);
                array_push($usedAssets, ...$built['assets']);
                if ($kind === 'layout' || $type === 'cover') {
                    array_push($firstFold, ...$built['assets']);
                }
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
            $css = $sanitizer->sanitize($files['theme.css'], $slug, 'theme.css', $report, $assets, [...$families, ...self::ENGINE_FAMILIES, ...($this->context->allowEngineFonts ? self::ENGINE_REMOTE_FAMILIES : [])], $classKinds);
            $artifacts['css'] = $css;
            array_push($usedAssets, ...$sanitizer->usedAssets());
        } elseif (isset($files['theme.css'])) {
            $report->error('theme.css', 'slug_invalid', 'No se puede acotar el CSS sin un slug válido.');
        } else {
            $report->warning('theme.css', 'empty_css', 'Sin theme.css: solo se aplican los tokens.');
        }

        $this->crossChecks($files, $assets, array_unique($usedAssets), array_unique($firstFold), $manifest, $json['tokens.json'] ?? null, $report, $artifacts);

        foreach (['manifest.json' => 'manifest', 'tokens.json' => 'tokens', 'sections.json' => 'sections', 'editable.json' => 'editable', 'strings.json' => 'strings'] as $file => $key) {
            if (is_array($json[$file] ?? null)) {
                $artifacts['json'][$key] = $json[$file];
            }
        }
        foreach ($files as $path => $bytes) {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (str_starts_with($path, 'assets/') && in_array($ext, ['webp', 'png', 'jpg', 'jpeg', 'woff2'], true)) {
                $artifacts['blobs'][$path] = $bytes;
            }
        }
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
        if (JsonPruner::hasNonFinite($value)) {
            $report->error($name, $this->jsonCode($name), 'El JSON contiene un número que no es finito (p. ej. 1e999).');

            return null;
        }

        // Only the keys the contract declares survive (W6-12): the rest is never validated, stored or shown.
        return JsonPruner::forFile($name, $value);
    }

    private function jsonCode(string $name): string
    {
        return match ($name) {
            'manifest.json' => 'manifest_invalid',
            'tokens.json' => 'tokens_invalid',
            'sections.json' => 'sections_invalid',
            'strings.json' => 'strings_invalid',
            default => 'editable_invalid',
        };
    }

    private function manifest(array $m, Report $report): void
    {
        $f = 'manifest.json';
        $bad = fn (string $field, string $code = 'manifest_invalid', string $msg = 'Valor inválido.') => $report->error($f, $code, "{$field}: {$msg}");
        $installed = $this->context->installed;

        if (($m['schema'] ?? null) !== 1) {
            $bad('schema');
        }
        $slug = $m['slug'] ?? null;
        if (! is_string($slug) || ! preg_match('/^[a-z][a-z0-9-]{1,31}$/', $slug)) {
            $bad('slug', 'slug_invalid');
        } elseif (in_array($slug, self::RESERVED_SLUGS, true) && ! $this->context->allowReservedSlugs) {
            // `classic` belongs to the system: an upload would adopt its templates (W6-15); the others would shadow engine keyframes.
            $bad('slug', 'slug_invalid', 'ese slug está reservado al sistema (solo lo instala la consola de confianza)');
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

        $level = $m['level'] ?? null;
        if ($level !== 1 && $level !== 2) {
            $bad('level', 'level_not_allowed', 'el nivel es 1 (solo datos) o 2 (componentes registrados)');
        } elseif ($level === 2 && is_string($slug) && ! in_array($slug, $this->context->level2Slugs, true)) {
            $bad('level', 'level2_slug_not_registered', 'un paquete de nivel 2 solo se instala si su slug está registrado en el despliegue');
        }
        if (is_string($slug) && isset($this->context->levels[$slug]) && is_int($level) && $this->context->levels[$slug] !== $level) {
            $bad('slug', 'slug_taken', 'ese slug pertenece a un paquete de otro nivel');
        }

        $types = $m['eventTypes'] ?? null;
        if (! is_array($types) || $types === [] || ! array_is_list($types) || array_diff($types, ['quinceanera', 'wedding']) !== [] || count($types) !== count(array_unique($types))) {
            $bad('eventTypes');
        }
        if (! in_array($m['tier'] ?? null, ['free', 'premium'], true)) {
            $bad('tier');
        }
        $visibility = $m['visibility'] ?? 'public';
        if (! in_array($visibility, ['public', 'private'], true)) {
            $bad('visibility');
        } elseif (is_string($slug) && isset($this->context->visibilities[$slug]) && $this->context->visibilities[$slug] !== $visibility) {
            $bad('visibility', 'visibility_changed', 'la visibilidad no cambia entre versiones; para pasar a público usa un slug nuevo');
        }
        if (! is_int($m['engineMin'] ?? null) || $m['engineMin'] < 1) {
            $bad('engineMin');
        } elseif ($m['engineMin'] > $this->context->engine) {
            $bad('engineMin', 'engine_too_new', "el motor actual es {$this->context->engine}");
        }
        $author = $m['author'] ?? null;
        if (! is_array($author) || ! $this->plain($author['name'] ?? null, 80)
            || (array_key_exists('url', $author) && ! UrlGuard::isOptionalHttps($author['url']))) {
            $bad('author');
        }
        $license = $m['license'] ?? null;
        if (! is_array($license) || ! $this->plain($license['id'] ?? null, 60)
            || ($license['document'] ?? null) !== 'LICENSES.md'
            || ! $this->plain($license['summary'] ?? null, 300)) {
            $report->error($f, 'license_missing', 'license.id, license.summary y license.document="LICENSES.md" son obligatorios.');
        }
        $marks = $m['thirdPartyMarks'] ?? null;
        if (! is_array($marks)) {
            $bad('thirdPartyMarks');
        } elseif ($marks !== []) {
            foreach ($marks as $mark) {
                if (! is_array($mark) || ! $this->plain($mark['name'] ?? null, 120) || ! $this->plain($mark['owner'] ?? null, 120) || ! $this->plain($mark['usage'] ?? null, 300)) {
                    $bad('thirdPartyMarks', 'manifest_invalid', 'cada marca necesita name, owner y usage');
                    break;
                }
                if (array_key_exists('url', $mark) && ! UrlGuard::isOptionalHttps($mark['url'])) {
                    $bad('thirdPartyMarks', 'manifest_invalid', 'la url de una marca solo admite https:// público');
                    break;
                }
            }
            $report->warning($f, 'third_party_marks_declared', 'El paquete declara marcas de terceros: revisión humana obligatoria.');
            if ($visibility === 'public') {
                $report->error($f, 'third_party_marks_public', 'Un paquete con marcas de terceros solo puede ser privado.');
            }
        }
        if (! is_array($m['assetsCredits'] ?? null)) {
            $bad('assetsCredits');
        }
        $this->variants($m['variants'] ?? [], $report);
    }

    /** A free text of the documents: plain, one line, no markup and no control characters (W6-12). */
    private function plain(mixed $text, int $max, bool $required = true): bool
    {
        return is_string($text) && ($text !== '' || ! $required) && mb_strlen($text) <= $max && ! preg_match('/[<>\x00-\x1F\x7F]/', $text);
    }

    /** `{es, en}` of plain texts up to `$max` characters each. */
    private function plainPair(mixed $pair, int $max): bool
    {
        return is_array($pair) && $this->plain($pair['es'] ?? null, $max) && $this->plain($pair['en'] ?? null, $max);
    }

    /**
     * `variants`: options the package defines for its own editor (`{id: {values: [...], default}}`). With the AST
     * model the engine no longer owns the variant table, so what is checked is the shape.
     */
    private function variants(mixed $variants, Report $report): void
    {
        if (! is_array($variants) || ($variants !== [] && array_is_list($variants)) || count($variants) > 12) {
            $report->error('manifest.json', 'variant_unknown', 'variants debe ser un objeto de hasta 12 opciones.');

            return;
        }
        foreach ($variants as $id => $variant) {
            $values = is_array($variant) ? ($variant['values'] ?? null) : null;
            if (! is_string($id) || ! preg_match('/^[a-z][a-z0-9._-]{0,31}$/', $id) || ! is_array($values) || ! array_is_list($values)
                || $values === [] || count($values) > 8
                || array_filter($values, fn ($v) => ! is_string($v) || ! preg_match('/^[a-z0-9][a-z0-9_-]{0,23}$/', $v)) !== []
                || ! is_string($variant['default'] ?? null) || ! in_array($variant['default'], $values, true)) {
                $report->error('manifest.json', 'variant_unknown', 'Opción de variante mal declarada (id, values y un default dentro de values).', null, (string) $id);
            }
        }
    }

    /**
     * @param  array<string, string>  $files
     * @param  list<string>  $assets
     */
    private function assets(array &$files, array $assets, Report $report, array &$artifacts): void
    {
        $svg = new SvgSanitizer;
        foreach ($assets as $path) {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $bytes = $files[$path];

            if (! in_array($ext, ['svg', 'webp', 'png', 'jpg', 'jpeg', 'woff2'], true)) {
                $report->error($path, 'ext_not_allowed', 'assets/ solo admite svg, webp, png, jpg y woff2.');

                continue;
            }
            if ($ext === 'svg') {
                $clean = $svg->sanitize($bytes, $path, $report);
                if ($clean !== null) {
                    $artifacts['svg'][$path] = $clean;
                }
            } elseif ($ext === 'woff2') {
                $this->woff2($bytes, $path, $report);
            } else {
                $clean = $this->raster($bytes, $path, $ext, $report);
                if ($clean !== null) {
                    $files[$path] = $clean;
                }
            }
        }
    }

    private function woff2(string $bytes, string $path, Report $report): void
    {
        if (strlen($bytes) > $this->limits->woff2Bytes) {
            $report->error($path, 'file_too_large', 'Fuente mayor de ' . intdiv($this->limits->woff2Bytes, 1024) . ' KB.');

            return;
        }
        if (($problem = Woff2Validator::problem($bytes)) !== null) {
            $report->error($path, 'woff2_invalid', $problem);
        }
    }

    /** @return string|null the re-encoded image (the only bytes that are ever stored), null on error */
    private function raster(string $bytes, string $path, string $ext, Report $report): ?string
    {
        if (strlen($bytes) > $this->limits->rasterBytes) {
            $report->error($path, 'file_too_large', 'Imagen mayor de ' . intdiv($this->limits->rasterBytes, 1024) . ' KB.');

            return null;
        }
        // The header is read first, so a pixel bomb (tiny file, huge canvas) is refused before anything is decoded.
        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            $report->error($path, 'image_decode', 'No se pudo leer la cabecera de la imagen.');

            return null;
        }
        $expected = match ($ext) {
            'webp' => IMAGETYPE_WEBP,
            'png' => IMAGETYPE_PNG,
            default => IMAGETYPE_JPEG,
        };
        if ($info[2] !== $expected) {
            $report->error($path, 'mime_mismatch', 'El contenido no corresponde a la extensión.', null, (string) image_type_to_mime_type($info[2]));

            return null;
        }
        [$w, $h] = $info;
        if ($w < 1 || $h < 1 || $w > $this->limits->imageSide || $h > $this->limits->imageSide || $w * $h > $this->limits->imagePixels) {
            $report->error($path, 'image_dimensions', 'Dimensiones fuera de límites.', null, "{$w}x{$h}");

            return null;
        }
        // Decode and encode again: what is stored carries no metadata, no trailing bytes and no foreign payload.
        $clean = RasterCleaner::clean($bytes, $ext);
        if ($clean === null) {
            $report->error($path, 'image_decode', 'No se pudo decodificar la imagen.');

            return null;
        }
        if (strlen($clean) > $this->limits->rasterBytes) {
            $report->error($path, 'file_too_large', 'Imagen mayor de ' . intdiv($this->limits->rasterBytes, 1024) . ' KB tras normalizarla.');

            return null;
        }

        return $clean;
    }

    /**
     * @param  array<string, string>  $files
     * @return list<string> font families declared with own files
     */
    private function tokens(array $t, array $files, ?array $manifest, Report $report): array
    {
        $f = 'tokens.json';
        $families = [];

        $colors = $t['colors'] ?? null;
        $this->colorSet($colors, 'colors', $report);

        $fontFiles = 0;
        $fonts = $t['fonts'] ?? [];
        if (! is_array($fonts)) {
            $report->error($f, 'tokens_invalid', 'fonts debe ser un objeto.');
            $fonts = [];
        }
        foreach ($fonts as $role => $font) {
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
            if (! is_array($list) || ! array_is_list($list) || count($list) > 4) {
                $report->error($f, 'tokens_invalid', "fonts.{$role}.files debe ser una lista de hasta 4 archivos.");

                continue;
            }
            if ($list === [] && ! in_array($family, self::ENGINE_FAMILIES, true)
                && ! ($this->context->allowEngineFonts && in_array($family, self::ENGINE_REMOTE_FAMILIES, true))) {
                $report->error($f, 'font_not_self_hosted', 'La fuente debe traer sus .woff2 en assets/fonts/.', null, $family);
            }
            foreach ($list as $entry) {
                $file = is_array($entry) ? ($entry['file'] ?? null) : null;
                if (! is_string($file) || ! str_starts_with($file, 'assets/fonts/') || ! str_ends_with($file, '.woff2') || ! isset($files[$file])) {
                    $report->error($f, 'font_missing_file', 'Archivo de fuente ausente o fuera de assets/fonts/.', null, (string) $file);
                }
                $weight = is_array($entry) ? ($entry['weight'] ?? null) : null;
                if (! is_int($weight) || $weight < 100 || $weight > 900 || $weight % 100 !== 0 || ! in_array($entry['style'] ?? null, ['normal', 'italic'], true)) {
                    $report->error($f, 'tokens_invalid', "fonts.{$role}: weight (100-900) y style (normal|italic) son obligatorios.", null, (string) $file);
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
        foreach (array_diff(['heading', 'body', 'script'], array_keys($fonts)) as $role) {
            $report->error($f, 'tokens_invalid', "Falta la fuente «{$role}» (heading, body y script son obligatorias).");
        }

        $presets = $t['presets'] ?? null;
        if (! is_array($presets) || $presets === [] || array_is_list($presets) || count($presets) > 8) {
            $report->error($f, 'tokens_invalid', 'presets debe ser un objeto de 1 a 8 preajustes.');
        } else {
            foreach ($presets as $id => $preset) {
                if (! is_string($id) || ! preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $id) || ! is_array($preset)
                    || ! $this->labelOk($preset['label'] ?? null)) {
                    $report->error($f, 'tokens_invalid', 'Preajuste mal formado (id, label es/en y colors).', null, (string) $id);

                    continue;
                }
                $this->colorSet($preset['colors'] ?? null, "presets.{$id}.colors", $report);
            }
            if (! is_string($t['preset'] ?? null) || ! isset($presets[$t['preset']])) {
                $report->error($f, 'tokens_invalid', 'preset debe nombrar uno de los preajustes.');
            }
            if (($manifest['tier'] ?? null) === 'free' && count($presets) > 3) {
                $report->error($f, 'tokens_invalid', 'Un paquete gratuito trae entre 1 y 3 preajustes.');
            }
        }

        return $families;
    }

    private function colorSet(mixed $colors, string $at, Report $report): void
    {
        if (! is_array($colors) || array_is_list($colors)) {
            $report->error('tokens.json', 'tokens_invalid', "{$at} ausente.");

            return;
        }
        foreach (self::COLOR_KEYS as $key) {
            if (! is_string($colors[$key] ?? null) || ! preg_match(self::HEX, $colors[$key])) {
                $report->error('tokens.json', 'color_invalid', "{$at}.{$key} debe ser hex.", null, (string) ($colors[$key] ?? ''));
            }
        }
        if (isset($colors['wash']) && (! is_string($colors['wash']) || ! preg_match(self::HEX, $colors['wash']))) {
            $report->error('tokens.json', 'color_invalid', "{$at}.wash debe ser hex.");
        }
        foreach (array_diff(array_keys($colors), [...self::COLOR_KEYS, 'wash']) as $extra) {
            $report->error('tokens.json', 'tokens_invalid', "{$at}.{$extra}: color desconocido.");
        }
    }

    private function labelOk(mixed $label): bool
    {
        return is_array($label) && is_string($label['es'] ?? null) && is_string($label['en'] ?? null)
            && $label['es'] !== '' && $label['en'] !== ''
            && mb_strlen($label['es']) <= 60 && mb_strlen($label['en']) <= 60 && ! preg_match('/[<>]/', $label['es'] . $label['en']);
    }

    private function sectionsJson(array $s, ?array $manifest, Report $report): void
    {
        $f = 'sections.json';
        $declared = is_array($manifest['eventTypes'] ?? null) ? $manifest['eventTypes'] : [];
        foreach (array_diff(array_keys($s), $declared) as $extra) {
            $report->error($f, 'sections_invalid', 'Lista de un tipo de evento que el manifiesto no declara.', null, (string) $extra);
        }
        foreach ($declared as $type) {
            $list = $s[$type] ?? null;
            if (! is_array($list) || ! array_is_list($list) || $list === []) {
                $report->error($f, 'sections_invalid', "Falta la lista de secciones de {$type}.");

                continue;
            }
            foreach (TemplateSchema::sectionErrors($list) as $path => $message) {
                $report->error($f, 'sections_invalid', $message, null, 'sections.' . $type . ltrim((string) preg_replace('/^sections/', '', $path), ''));
            }
        }
    }

    /**
     * strings.json: the interface texts of the package per language (`t.<key>` variables).
     *
     * @return list<string> the keys
     */
    private function strings(array $s, Report $report): array
    {
        $f = 'strings.json';
        $keys = null;
        foreach (['es', 'en'] as $lang) {
            $set = $s[$lang] ?? null;
            if (! is_array($set) || ($set !== [] && array_is_list($set)) || count($set) > 60) {
                $report->error($f, 'strings_invalid', "Falta el idioma {$lang} (un objeto de hasta 60 textos).");

                return [];
            }
            foreach ($set as $key => $text) {
                if (! is_string($key) || ! preg_match(BlockCatalog::STRING_KEY, $key) || ! is_string($text) || mb_strlen($text) > 200 || preg_match('/[<>]/', $text) || preg_match('/\{\{|\}\}/', $text)) {
                    $report->error($f, 'strings_invalid', "{$lang}: clave o texto inválido (texto plano de hasta 200 caracteres, sin llaves).", null, (string) $key);

                    return [];
                }
            }
            $names = array_keys($set);
            sort($names);
            if ($keys !== null && $keys !== $names) {
                $report->error($f, 'strings_invalid', 'es y en deben tener exactamente las mismas claves.');

                return [];
            }
            $keys = $names;
        }
        foreach (array_diff(array_keys($s), ['es', 'en']) as $extra) {
            $report->error($f, 'strings_invalid', 'Solo se admiten los idiomas es y en.', null, (string) $extra);
        }

        return $keys ?? [];
    }

    private function editable(array $e, ?array $manifest, Report $report): void
    {
        $f = 'editable.json';
        $fields = $e['fields'] ?? null;
        if (! is_array($fields) || ! array_is_list($fields) || count($fields) > 40) {
            $report->error($f, 'editable_invalid', 'fields debe ser una lista de hasta 40 elementos.');

            return;
        }
        $groups = $e['groups'] ?? [];
        $groupIds = [];
        if (! is_array($groups) || ! array_is_list($groups) || count($groups) > 8) {
            $report->error($f, 'editable_invalid', 'groups debe ser una lista de hasta 8 grupos.');
            $groups = [];
        }
        foreach ($groups as $i => $group) {
            if (! is_array($group) || ! is_string($group['id'] ?? null) || ! preg_match('/^[a-z][a-z0-9_]{0,31}$/', $group['id']) || ! $this->labelOk($group['label'] ?? null)) {
                $report->error($f, 'editable_invalid', "groups.{$i} inválido.");

                continue;
            }
            $groupIds[$group['id']] = true;
        }

        $variants = is_array($manifest['variants'] ?? null) ? $manifest['variants'] : [];
        $this->currentEditableIds = array_values(array_filter(array_column(array_filter($fields, 'is_array'), 'id'), 'is_string'));
        $seen = [];
        foreach ($fields as $i => $field) {
            if (! is_array($field)) {
                $report->error($f, 'editable_invalid', "fields.{$i} debe ser un objeto.");

                continue;
            }
            $id = $field['id'] ?? null;
            if (! is_string($id) || ! preg_match('/^[a-z][a-z0-9_]{0,31}$/', $id) || isset($seen[$id])) {
                $report->error($f, 'editable_invalid', "fields.{$i}.id inválido o repetido.");
            }
            $seen[is_string($id) ? $id : ''] = true;
            $type = $field['type'] ?? null;
            if (! in_array($type, ['image', 'text', 'color', 'choice', 'toggle'], true)) {
                $report->error($f, 'editable_invalid', "fields.{$i}.type desconocido.");
            }
            if (! $this->labelOk($field['label'] ?? null)) {
                $report->error($f, 'editable_invalid', "fields.{$i}.label necesita es y en.");
            }
            if (isset($field['help']) && ! $this->plainPair($field['help'], 300)) {
                $report->error($f, 'editable_invalid', "fields.{$i}.help necesita es y en, texto plano de hasta 300 caracteres.");
            }
            foreach (['aspect' => '/^\d{1,2}:\d{1,2}$/', 'fallbackSlot' => '/^[a-z][a-z0-9-]{0,40}$/'] as $key => $re) {
                if (isset($field[$key]) && (! is_string($field[$key]) || ! preg_match($re, $field[$key]))) {
                    $report->error($f, 'editable_invalid', "fields.{$i}.{$key} inválido.");
                }
            }
            if (isset($field['minWidth']) && (! is_int($field['minWidth']) || $field['minWidth'] < 1 || $field['minWidth'] > 8000)) {
                $report->error($f, 'editable_invalid', "fields.{$i}.minWidth debe ser un entero entre 1 y 8000.");
            }
            foreach (['required', 'multiline'] as $key) {
                if (isset($field[$key]) && ! is_bool($field[$key])) {
                    $report->error($f, 'editable_invalid', "fields.{$i}.{$key} debe ser booleano.");
                }
            }
            if (isset($field['default']) && ! is_bool($field['default']) && ! $this->plain($field['default'], 100)) {
                $report->error($f, 'editable_invalid', "fields.{$i}.default inválido.");
            }
            if ($type === 'choice') {
                $choices = $field['choices'] ?? null;
                if (! is_array($choices) || ! array_is_list($choices) || count($choices) < 1 || count($choices) > 12) {
                    $report->error($f, 'editable_invalid', "fields.{$i}.choices debe ser una lista de 1 a 12 opciones.");
                } else {
                    foreach ($choices as $choice) {
                        if (! is_array($choice) || ! is_string($choice['value'] ?? null) || ! preg_match('/^[a-z0-9][a-z0-9_-]{0,23}$/', $choice['value']) || ! $this->labelOk($choice['label'] ?? null)) {
                            $report->error($f, 'editable_invalid', "fields.{$i}.choices: cada opción necesita value (a-z0-9_-) y label es/en sin etiquetas.");
                            break;
                        }
                    }
                }
            }
            if (isset($field['group']) && ! isset($groupIds[$field['group']])) {
                $report->error($f, 'editable_invalid', "fields.{$i}.group no existe.");
            }
            if (isset($field['plan']) && ! in_array($field['plan'], ['free', 'full'], true)) {
                $report->error($f, 'editable_invalid', "fields.{$i}.plan debe ser free o full.");
            }
            $bind = $field['bind'] ?? null;
            if (! is_array($bind) || ! $this->bindOk($bind, $type)) {
                $report->error($f, 'editable_bind_unknown', "fields.{$i}.bind desconocido.");
            } elseif (isset($bind['option']) && $type === 'choice') {
                $declared = $variants[$bind['option']]['values'] ?? null;
                $choices = array_column(is_array($field['choices'] ?? null) ? $field['choices'] : [], 'value');
                if ($declared === null || $choices === [] || array_diff($choices, $declared) !== []) {
                    $report->error($f, 'editable_bind_unknown', "fields.{$i}: choices debe usar valores de la variante «{$bind['option']}» del manifiesto.");
                }
            }
            if ($type === 'text') {
                foreach (['es', 'en'] as $lang) {
                    if (isset($field['max'][$lang]) && (! is_int($field['max'][$lang]) || $field['max'][$lang] < 1 || $field['max'][$lang] > 1000)) {
                        $report->error($f, 'editable_invalid', "fields.{$i}.max.{$lang} debe estar entre 1 y 1000.");
                    }
                }
            }
        }
    }

    /** @param array<string, mixed> $bind */
    private function bindOk(array $bind, mixed $type): bool
    {
        $keys = array_keys($bind);
        sort($keys);
        $section = $bind['section'] ?? null;

        if ($section !== null) {
            if (! in_array($section, BlockCatalog::SECTION_TYPES, true)) {
                return false;
            }
            if (isset($bind['setting'])) {
                return $type === 'image' && $bind['setting'] === 'image' && $keys === (isset($bind['focus']) ? ['focus', 'section', 'setting'] : ['section', 'setting'])
                    && (! isset($bind['focus']) || $bind['focus'] === 'focus');
            }

            return in_array($type, ['text'], true) && $keys === ['field', 'section'] && in_array($bind['field'] ?? null, ['title', 'text'], true);
        }
        if (isset($bind['theme'])) {
            return $keys === ['theme'] && is_string($bind['theme'])
                && (($type === 'color' && preg_match('/^colors\.(surface|surfaceAlt|ink|inkSoft|accent|primary|wash)$/', $bind['theme']))
                    || ($type === 'choice' && ($bind['theme'] === 'preset' || preg_match('/^fonts\.(heading|body|script)$/', $bind['theme']))));
        }
        if (isset($bind['option'])) {
            return $keys === ['option'] && is_string($bind['option']) && preg_match('/^[a-z][a-z0-9._-]{0,31}$/', $bind['option']) && in_array($type, ['choice', 'toggle'], true);
        }

        return false;
    }

    /** @param array<string, string> $files */
    private function previews(array $files, Report $report): void
    {
        foreach (['preview/mobile.png' => [[390, 844], [780, 1688]], 'preview/desktop.png' => [[1366, 900], [2732, 1800]]] as $path => $sizes) {
            if (! isset($files[$path])) {
                $report->error($path, 'preview_missing', 'Captura obligatoria ausente.');

                continue;
            }
            if (strlen($files[$path]) > $this->limits->previewBytes) {
                $report->error($path, 'file_too_large', 'La captura supera ' . intdiv($this->limits->previewBytes, 1024) . ' KB.');

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
        if (isset($files['preview/sample.json']) && strlen($files['preview/sample.json']) > 4096) {
            $report->error('preview/sample.json', 'file_too_large', 'sample.json supera 4 KB.');
        }
    }

    /**
     * @param  array<string, string>  $files
     * @param  list<string>  $assets
     * @param  array<int, string>  $used
     * @param  array<int, string>  $firstFold  assets referenced by the cover and the layout
     */
    private function crossChecks(array $files, array $assets, array $used, array $firstFold, ?array $manifest, mixed $tokens, Report $report, array &$artifacts): void
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
            foreach (['title' => 120, 'author' => 120, 'source' => 300, 'license' => 80] as $k => $max) {
                if (! is_string($credit[$k] ?? null) || $credit[$k] === '') {
                    $report->error('manifest.json', 'license_asset_missing', "assetsCredits.{$k} vacío.", null, $path);
                } elseif (! $this->plain($credit[$k], $max)) {
                    $report->error('manifest.json', 'manifest_invalid', "assetsCredits.{$k}: texto plano de hasta {$max} caracteres, sin etiquetas ni caracteres de control.", null, $path);
                }
            }
            // A credit that names a URL as its source must name a public https one; `original` and plain names are fine.
            if (is_string($credit['source'] ?? null) && preg_match('/^[a-z][a-z0-9+.-]*:/i', $credit['source']) && ! UrlGuard::isHttps($credit['source'])) {
                $report->error('manifest.json', 'manifest_invalid', 'assetsCredits.source: si es un enlace debe ser https:// público.', null, $path);
            }
            if (array_key_exists('url', $credit) && ! UrlGuard::isOptionalHttps($credit['url'])) {
                $report->error('manifest.json', 'manifest_invalid', 'assetsCredits.url solo admite https:// público (sin credenciales, localhost ni IP).', null, $path);
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
        $size = fn (string $p) => isset($artifacts['svg'][$p]) ? strlen($artifacts['svg'][$p]) : strlen($files[$p] ?? '');
        $css = strlen((string) $artifacts['css']);
        $runtime = $css;
        foreach ($assets as $path) {
            $runtime += $size($path);
        }
        if ($runtime > $this->limits->runtimeMax) {
            $report->error('package.zip', 'package_too_large', 'El peso en ejecución supera 1 MB.', null, (string) $runtime);
        } elseif ($runtime > $this->limits->runtimeWarn) {
            $report->warning('package.zip', 'budget_exceeded', 'El peso en ejecución supera 350 KB.', null, (string) $runtime);
        }

        // First fold: the CSS, the script font and what the cover and the layout draw.
        $fold = $css;
        $foldPaths = array_filter($firstFold, fn (string $p) => isset($files[$p]));
        foreach (is_array($tokens) ? ($tokens['fonts']['script']['files'] ?? []) : [] as $entry) {
            if (is_array($entry) && is_string($entry['file'] ?? null)) {
                $foldPaths[] = $entry['file'];
            }
        }
        foreach (array_unique($foldPaths) as $p) {
            $fold += $size($p);
        }
        if ($fold > $this->limits->firstFoldWarn) {
            $report->warning('package.zip', 'first_fold_budget', 'El primer pliegue supera 180 KB.', null, (string) $fold);
        }

        $this->contrast($tokens, $report);
        $this->removals($manifest, $report);

        foreach ($files as $path => $bytes) {
            $artifacts['files'][$path] = ['size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
        }
        $report->stats += [
            'files' => count($files),
            'uncompressedBytes' => array_sum(array_map('strlen', $files)),
            'runtimeBytes' => $runtime,
            'firstFoldBytes' => $fold,
        ];
    }

    /** Informative: text colors against their surfaces (WCAG AA, 4.5:1), for the active colors and every preset. */
    private function contrast(mixed $tokens, Report $report): void
    {
        if (! is_array($tokens)) {
            return;
        }
        $sets = ['colors' => $tokens['colors'] ?? null];
        foreach (is_array($tokens['presets'] ?? null) ? $tokens['presets'] : [] as $id => $preset) {
            $sets["presets.{$id}"] = is_array($preset) ? ($preset['colors'] ?? null) : null;
        }
        foreach ($sets as $at => $c) {
            if (! is_array($c)) {
                continue;
            }
            foreach (['ink', 'inkSoft'] as $text) {
                foreach (['surface', 'surfaceAlt'] as $surface) {
                    $ratio = $this->ratio($c[$text] ?? null, $c[$surface] ?? null);
                    if ($ratio !== null && $ratio < 4.5) {
                        $report->warning('tokens.json', 'contrast_low', sprintf('%s: %s sobre %s tiene contraste %.1f:1 (mínimo recomendado 4.5:1).', $at, $text, $surface, $ratio));
                    }
                }
            }
        }
    }

    private function ratio(mixed $a, mixed $b): ?float
    {
        $la = $this->luminance($a);
        $lb = $this->luminance($b);
        if ($la === null || $lb === null) {
            return null;
        }

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    private function luminance(mixed $hex): ?float
    {
        if (! is_string($hex) || ! preg_match(self::HEX, $hex)) {
            return null;
        }
        $h = substr($hex, 1);
        if (strlen($h) <= 4) {
            $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
        }
        $rgb = array_map(function (string $pair) {
            $v = hexdec($pair) / 255;

            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, str_split(substr($h, 0, 6), 2));

        return 0.2126 * $rgb[0] + 0.7152 * $rgb[1] + 0.0722 * $rgb[2];
    }

    /** Warns when this version drops an editable field or a variant value the previous installed version had. */
    private function removals(?array $manifest, Report $report): void
    {
        $slug = $manifest['slug'] ?? null;
        $previous = is_string($slug) ? ($this->context->previous[$slug] ?? null) : null;
        if ($previous === null) {
            return;
        }
        $current = $this->currentEditableIds;
        foreach (array_diff($previous['fields'], $current) as $id) {
            $report->warning('editable.json', 'field_removed', 'La versión anterior tenía este campo editable: quitarlo exige un cambio mayor.', null, $id);
        }
        $variants = is_array($manifest['variants'] ?? null) ? $manifest['variants'] : [];
        foreach ($previous['variants'] as $id => $values) {
            $now = $variants[$id]['values'] ?? [];
            foreach (array_diff($values, is_array($now) ? $now : []) as $value) {
                $report->warning('manifest.json', 'variant_removed', 'La versión anterior tenía este valor de variante.', null, "{$id}={$value}");
            }
        }
    }

    /** @param array<string, string> $a @param array<string, string> $b */
    private function mergeKinds(array $a, array $b): array
    {
        $rank = ['wrapper' => 0, 'photo' => 1, 'system' => 2];
        foreach ($b as $k => $kind) {
            if (! isset($a[$k]) || $rank[$kind] > $rank[$a[$k]]) {
                $a[$k] = $kind;
            }
        }

        return $a;
    }
}
