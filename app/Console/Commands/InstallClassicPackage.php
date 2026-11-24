<?php

namespace App\Console\Commands;

use App\Http\Services\Admin\Templates\Packages\ClassicSource;
use Illuminate\Console\Command;
use Illuminate\Http\Exceptions\HttpResponseException;
use ZipArchive;

/**
 * Trusted console install of the reserved `classic` package (free-cover-composition-contract 6.2, C2). Idempotent. It
 * installs the package and its new, immutable versions of `classic-quinceanera` and `classic-wedding`; it never changes
 * what the public sees (that is `templates:classic-source`).
 */
class InstallClassicPackage extends Command
{
    protected $signature = 'templates:install-classic
        {path : The classic package: a .zip, or the folder dev-packages/classic/<version> (package.json and ast.json at its root are left out)}
        {--reason= : Why (5 to 500 characters, goes to the audit log)}';

    protected $description = 'Install the reserved classic template package from the trusted console (idempotent; does not switch the source)';

    public function handle(ClassicSource $classic): int
    {
        $reason = trim((string) $this->option('reason'));
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            $this->error('Indica --reason="..." (entre 5 y 500 caracteres).');

            return self::INVALID;
        }

        $path = rtrim((string) $this->argument('path'), '/');
        $temp = null;
        if (is_dir($path)) {
            $temp = $this->zipFolder($path);
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
            $result = $classic->install($path, $reason);
        } catch (HttpResponseException $e) {
            $this->error((string) $e->getResponse()->getContent());

            return self::FAILURE;
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            if ($temp !== null) {
                @unlink($temp);
            }
        }

        $p = $result['package'];
        $this->info(($result['status'] === 'installed' ? 'Instalado' : 'Ya estaba instalado, sin cambios') . ": {$p->slug} {$p->semver} (id {$p->id}).");
        $this->line('Origen de la Clásica ahora: ' . $classic->current() . ' (cámbialo con templates:classic-source).');
        if ($result['missingModules'] !== []) {
            $this->warn('Al paquete le faltan módulos: ' . implode(', ', $result['missingModules']) . '. El interruptor no se podrá poner en package.');
        }

        return self::SUCCESS;
    }

    private function zipFolder(string $dir): ?string
    {
        $temp = tempnam(sys_get_temp_dir(), 'classic') . '.zip';
        $zip = new ZipArchive;
        if ($zip->open($temp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return null;
        }
        $root = realpath($dir);
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if ($file->isFile() && ! $file->isLink() && ! in_array($rel, ['package.json', 'ast.json'], true)) {
                $zip->addFile($file->getPathname(), $rel);
            }
        }
        $zip->close();

        return $temp;
    }
}
