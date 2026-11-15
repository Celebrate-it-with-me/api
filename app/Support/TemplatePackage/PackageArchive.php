<?php

namespace App\Support\TemplatePackage;

use ZipArchive;

/**
 * Safe reader of the uploaded .zip. Nothing is ever extracted to disk: every entry is read in streaming with a hard
 * byte cap (the sizes in the central directory are attacker controlled), validated by name and type, and returned as
 * a path => bytes map. Errors go to the report with the catalog codes of spec section 4.8.
 */
class PackageArchive
{
    public const ALLOWED_EXT = ['json', 'css', 'svg', 'webp', 'png', 'jpg', 'jpeg', 'avif', 'woff2', 'md', 'txt', 'html'];

    private const SEGMENT = '/^[a-z0-9][a-z0-9._-]{0,63}$/';

    private const IGNORED = ['.ds_store', 'thumbs.db'];

    public function __construct(private readonly Limits $limits = new Limits) {}

    /**
     * @return array<string, string>|null path => content, or null when the archive cannot be used at all
     */
    public function read(string $zipPath, Report $report): ?array
    {
        $size = @filesize($zipPath);
        if ($size === false || $size === 0) {
            $report->error('package.zip', 'not_a_zip', 'El archivo está vacío o no se puede leer.');

            return null;
        }
        if ($size > $this->limits->zipBytes) {
            $report->error('package.zip', 'package_too_large', 'El .zip supera el límite.', null, "{$size} bytes");

            return null;
        }

        $head = (string) file_get_contents($zipPath, false, null, 0, 4);
        if ($head !== "PK\x03\x04" && $head !== "PK\x05\x06") {
            $report->error('package.zip', 'not_a_zip', 'El contenido no tiene firma de zip.');

            return null;
        }

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            $report->error('package.zip', 'not_a_zip', 'No se pudo abrir el zip (corrupto o de varios discos).');

            return null;
        }

        try {
            return $this->readEntries($zip, $report);
        } finally {
            $zip->close();
        }
    }

    /** @return array<string, string>|null */
    private function readEntries(ZipArchive $zip, Report $report): ?array
    {
        if ($zip->numFiles > $this->limits->hardEntries) {
            $report->error('package.zip', 'zip_too_many_files', 'Demasiadas entradas.', null, (string) $zip->numFiles);

            return null;
        }

        $entries = [];
        $seen = [];
        $fileCount = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                $report->error('package.zip', 'not_a_zip', 'Entrada ilegible.', null, "#{$i}");

                return null;
            }
            $name = (string) $stat['name'];

            // Directories carry no content; macOS / Windows litter is ignored with a warning, never read.
            if (str_ends_with($name, '/') && ! str_contains($name, '..')) {
                continue;
            }
            if ($this->isIgnoredLitter($name)) {
                $report->warning($name, 'ignored_file', 'Archivo del sistema operativo ignorado.');

                continue;
            }

            if (! $this->nameIsSafe($name, $report)) {
                continue;
            }

            $this->checkMode($zip, $i, $name, $report);

            if (($stat['encryption_method'] ?? 0) !== 0) {
                $report->error($name, 'zip_encrypted', 'No se admiten entradas cifradas.');

                continue;
            }

            $lower = strtolower($name);
            if (isset($seen[$lower])) {
                $report->error($name, 'name_duplicate', 'Nombre repetido (se ignoran mayúsculas).');

                continue;
            }
            $seen[$lower] = true;

            $fileCount++;
            $entries[$i] = [$name, (int) $stat['size'], (int) $stat['comp_size']];
        }

        if ($fileCount > $this->limits->entries) {
            $report->error('package.zip', 'zip_too_many_files', 'Demasiados archivos.', null, "{$fileCount} > {$this->limits->entries}");

            return null;
        }

        $this->checkRoot(array_column($entries, 0), $report);

        $files = [];
        $total = 0;
        foreach ($entries as $i => [$name, $declared, $compressed]) {
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

            if (! in_array($ext, self::ALLOWED_EXT, true)) {
                $report->error($name, 'ext_not_allowed', 'Extensión no permitida.', null, $ext);

                continue;
            }
            if ($ext === 'zip') {
                $report->error($name, 'zip_nested', 'Zip anidado.');

                continue;
            }
            if (in_array($ext, ['md', 'txt'], true) && str_contains($name, '/')) {
                $report->error($name, 'ext_not_allowed', '.md y .txt solo en la raíz.');

                continue;
            }

            // Declared sizes decide the cheap rejections; the streaming read below decides the real ones.
            if ($compressed > 0 && $declared > 4096 && intdiv($declared, $compressed) > $this->limits->ratio) {
                $report->error($name, 'zip_bomb', 'Ratio de compresión sospechoso.', null, "{$declared}/{$compressed}");

                continue;
            }
            $cap = $this->limits->capFor($ext);
            if ($declared > $cap) {
                $report->error($name, 'file_too_large', 'El archivo supera el máximo de su tipo.', null, "{$declared} > {$cap}");

                continue;
            }

            $bytes = $this->readStream($zip, $i, $name, $declared, $total, $report);
            if ($bytes === null) {
                if ($report->hasError('zip_bomb') && $total >= $this->limits->uncompressedBytes) {
                    return null;
                }

                continue;
            }
            $total += strlen($bytes);
            $files[$name] = $bytes;
        }

        $this->checkContentTypes($files, $report);

        return $files;
    }

    /** Reads at most `declared + 1` bytes: a larger stream proves the header lied. */
    private function readStream(ZipArchive $zip, int $index, string $name, int $declared, int $total, Report $report): ?string
    {
        $stream = $zip->getStreamIndex($index);
        if ($stream === false) {
            $report->error($name, 'not_a_zip', 'No se pudo leer la entrada.');

            return null;
        }

        $buffer = '';
        $read = 0;
        while (! feof($stream)) {
            $chunk = fread($stream, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $read += strlen($chunk);
            if ($read > $declared) {
                fclose($stream);
                $report->error($name, 'zip_bomb', 'El contenido real supera el tamaño declarado.', null, "> {$declared}");

                return null;
            }
            if ($total + $read > $this->limits->uncompressedBytes) {
                fclose($stream);
                $report->error($name, 'zip_bomb', 'El total descomprimido supera el límite.');

                return null;
            }
            $buffer .= $chunk;
        }
        fclose($stream);

        if ($read !== $declared) {
            $report->error($name, 'zip_bomb', 'El tamaño real no coincide con el declarado.', null, "{$read} != {$declared}");

            return null;
        }

        return $buffer;
    }

    private function nameIsSafe(string $name, Report $report): bool
    {
        if (str_contains($name, "\0")) {
            $report->error('package.zip', 'name_invalid', 'Nombre con byte nulo.', null, $name);

            return false;
        }
        if (! mb_check_encoding($name, 'UTF-8')) {
            $report->error('package.zip', 'name_invalid', 'Nombre que no es UTF-8.', null, bin2hex($name));

            return false;
        }
        if (str_contains($name, '\\')) {
            $report->error($name, 'zip_path_traversal', 'Separador "\\" en el nombre.');

            return false;
        }
        if (str_starts_with($name, '/') || preg_match('/^[A-Za-z]:/', $name)) {
            $report->error($name, 'zip_absolute_path', 'Ruta absoluta.');

            return false;
        }

        // The two documents the contract names in capitals are the only exception to the lowercase rule.
        if (in_array($name, ['LICENSES.md', 'README.txt'], true)) {
            return true;
        }

        $segments = explode('/', $name);
        foreach ($segments as $segment) {
            if ($segment === '..' || $segment === '.' || $segment === '') {
                $report->error($name, 'zip_path_traversal', 'Segmento "." o ".." en la ruta.');

                return false;
            }
        }
        if (count($segments) > $this->limits->depth + 1) {
            $report->error($name, 'name_invalid', 'Demasiados niveles.');

            return false;
        }
        if (strlen($name) > $this->limits->pathLength) {
            $report->error($name, 'name_invalid', 'Ruta demasiado larga.');

            return false;
        }
        foreach ($segments as $segment) {
            if (! preg_match(self::SEGMENT, $segment)) {
                // ASCII-only, lowercase segments make confusable Unicode, spaces and control characters impossible.
                $report->error($name, 'name_invalid', 'El nombre debe ser minúsculas ASCII (a-z 0-9 . _ -).', null, $name);

                return false;
            }
        }

        return true;
    }

    private function checkMode(ZipArchive $zip, int $index, string $name, Report $report): void
    {
        $opsys = 0;
        $attr = 0;
        if (! $zip->getExternalAttributesIndex($index, $opsys, $attr)) {
            return;
        }
        if ($opsys !== ZipArchive::OPSYS_UNIX) {
            return;
        }
        $type = ($attr >> 16) & 0170000;
        if ($type === 0120000) {
            $report->error($name, 'zip_symlink', 'Enlace simbólico.');
        } elseif ($type !== 0 && $type !== 0100000 && $type !== 0040000) {
            $report->error($name, 'zip_symlink', 'Tipo de archivo especial (dispositivo, socket o similar).', null, decoct($type));
        }
    }

    private function isIgnoredLitter(string $name): bool
    {
        if (str_starts_with($name, '__MACOSX/')) {
            return true;
        }

        return in_array(strtolower(basename($name)), self::IGNORED, true);
    }

    /** @param list<string> $names */
    private function checkRoot(array $names, Report $report): void
    {
        if ($names === [] || in_array('manifest.json', $names, true)) {
            return;
        }
        $roots = array_unique(array_map(fn (string $n) => explode('/', $n)[0], $names));
        if (count($roots) === 1 && str_contains($names[0], '/')) {
            $report->error('package.zip', 'unexpected_root', 'El paquete va dentro de una carpeta; los archivos deben estar en la raíz.', null, $roots[0]);
        }
    }

    /** @param array<string, string> $files */
    private function checkContentTypes(array $files, Report $report): void
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $map = [
            'png' => ['image/png'],
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'webp' => ['image/webp'],
            'avif' => ['image/avif'],
            'svg' => ['image/svg+xml', 'text/xml', 'text/plain', 'application/xml'],
            'json' => ['application/json', 'text/plain'],
            'css' => ['text/css', 'text/plain', 'text/x-asm', 'application/x-empty'],
            'html' => ['text/html', 'text/plain', 'application/xml', 'text/xml'],
            'md' => ['text/plain', 'text/markdown', 'text/x-markdown'],
            'txt' => ['text/plain'],
        ];

        foreach ($files as $name => $bytes) {
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $mime = (string) $finfo->buffer($bytes);

            if ($mime === 'application/zip' || $mime === 'application/x-zip-compressed') {
                $report->error($name, 'zip_nested', 'Contenido de zip dentro del paquete.');

                continue;
            }
            if ($ext === 'woff2') {
                if (! str_starts_with($bytes, 'wOF2') || strlen($bytes) < 48) {
                    $report->error($name, 'woff2_invalid', 'Firma woff2 ausente.');
                } elseif ((int) unpack('N', substr($bytes, 8, 4))[1] !== strlen($bytes)) {
                    $report->error($name, 'woff2_invalid', 'La longitud de la cabecera no coincide con el archivo.');
                }

                continue;
            }
            $allowed = $map[$ext] ?? null;
            if ($allowed !== null && ! in_array($mime, $allowed, true)) {
                $report->error($name, 'mime_mismatch', 'El contenido no corresponde a la extensión.', null, "{$ext} => {$mime}");
            }
        }
    }
}
