<?php

namespace App\Console\Commands;

use App\Support\TemplatePackage\TemplatePackageValidator;
use App\Support\TemplatePackage\ValidationContext;
use Illuminate\Console\Command;
use ZipArchive;

/**
 * The same validator as the admin upload, on the command line (D-14): the designer's zip (or a folder, zipped on the fly)
 * goes in, the report comes out. Touches no database and no disk besides a temporary zip.
 */
class TemplatesLint extends Command
{
    protected $signature = 'templates:lint {path : A template package .zip, or a folder with its files}
        {--json : Print the report as JSON}
        {--allow-engine-fonts : Trusted console install: allow the engine fonts that load from Google (Pinyon Script)}
        {--allow-reserved-slugs : Trusted console install: allow the slugs reserved to the system (classic...)}';

    protected $description = 'Validate a template package and print the report (errors, warnings, stats)';

    public function handle(): int
    {
        $path = (string) $this->argument('path');
        $temp = null;

        if (is_dir($path)) {
            $temp = $this->zipFolder(rtrim($path, '/'));
            if ($temp === null) {
                $this->error('No se pudo comprimir la carpeta.');

                return self::FAILURE;
            }
            $path = $temp;
        } elseif (! is_file($path)) {
            $this->error("No existe: {$path}");

            return self::FAILURE;
        }

        try {
            $result = (new TemplatePackageValidator)->validate($path, new ValidationContext(allowEngineFonts: (bool) $this->option('allow-engine-fonts'), allowReservedSlugs: (bool) $this->option('allow-reserved-slugs')));
        } finally {
            if ($temp !== null) {
                @unlink($temp);
            }
        }

        $report = $result['report'];
        if ($this->option('json')) {
            $this->line((string) json_encode(['valid' => $result['valid']] + $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $result['valid'] ? self::SUCCESS : self::FAILURE;
        }

        $m = $report['manifest'];
        $this->line(sprintf('Paquete: %s %s (nivel %s, %s, %s)', $m['slug'] ?? '?', $m['version'] ?? '?', $m['level'] ?? '?', $m['visibility'] ?? 'public', $m['tier'] ?? '?'));
        $this->entries('ERRORES', $report['errors'], 'error');
        $this->entries('AVISOS', $report['warnings'], 'comment');
        $s = $report['stats'];
        $this->line(sprintf(
            'Archivos: %d · descomprimido: %d KB · en ejecución: %d KB · primer pliegue: %d KB · %d ms',
            $s['files'] ?? 0,
            intdiv((int) ($s['uncompressedBytes'] ?? 0), 1024),
            intdiv((int) ($s['runtimeBytes'] ?? 0), 1024),
            intdiv((int) ($s['firstFoldBytes'] ?? 0), 1024),
            $s['elapsedMs'] ?? 0
        ));
        $result['valid'] ? $this->info('VÁLIDO: se puede subir desde el admin.') : $this->error('NO VÁLIDO: ' . count($report['errors']) . ' error(es).');

        return $result['valid'] ? self::SUCCESS : self::FAILURE;
    }

    /** @param list<array<string, mixed>> $entries */
    private function entries(string $title, array $entries, string $style): void
    {
        if ($entries === []) {
            return;
        }
        $this->line("{$title} (" . count($entries) . ')');
        foreach ($entries as $e) {
            $where = $e['file'] . ($e['line'] !== null ? ':' . $e['line'] : '');
            $this->{$style}(sprintf('  [%s] %s - %s%s', $e['code'], $where, $e['message'], $e['detail'] !== null ? " ({$e['detail']})" : ''));
        }
    }

    private function zipFolder(string $dir): ?string
    {
        $temp = tempnam(sys_get_temp_dir(), 'lint') . '.zip';
        $zip = new ZipArchive;
        if ($zip->open($temp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return null;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile()) {
                $zip->addFile($file->getPathname(), str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1)));
            }
        }
        $zip->close();

        return $temp;
    }
}
