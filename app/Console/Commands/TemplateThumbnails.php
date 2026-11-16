<?php

namespace App\Console\Commands;

use App\Http\Services\Admin\Templates\Packages\PackageThumbnail;
use App\Models\TemplatePackage;
use Illuminate\Console\Command;
use ZipArchive;

/**
 * Regenerates the selector thumbnails (`invitation_templates.preview_image`, D-5) of packages installed before the
 * installer made them. The capture is not kept after the install, so the original .zip is needed for a package that has
 * none; it must be the very zip that was installed (its sha256 is checked). Idempotent: running it again changes nothing.
 */
class TemplateThumbnails extends Command
{
    protected $signature = 'templates:thumbnails {--zip= : The original .zip of an installed package, to build its thumbnail from preview/mobile.png}';

    protected $description = 'Build the selector thumbnail of installed template packages and link it to their templates';

    public function handle(PackageThumbnail $thumbnails): int
    {
        $zip = $this->option('zip');
        if ($zip !== null) {
            return $this->fromZip((string) $zip, $thumbnails);
        }

        $missing = 0;
        foreach (TemplatePackage::query()->where('status', '!=', TemplatePackage::WITHDRAWN)->orderBy('id')->get() as $package) {
            $linked = $thumbnails->link($package, $package->status !== TemplatePackage::ACTIVE);
            if ($thumbnails->existing($package) === null) {
                $missing++;
                $this->warn("{$package->slug} {$package->semver}: sin miniatura. Ejecuta de nuevo con --zip=<el .zip original>.");
            } else {
                $this->line("{$package->slug} {$package->semver}: miniatura lista" . ($linked > 0 ? " ({$linked} plantilla(s) enlazada(s))" : ''));
            }
        }

        return $missing === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function fromZip(string $path, PackageThumbnail $thumbnails): int
    {
        if (! is_file($path)) {
            $this->error("No existe: {$path}");

            return self::FAILURE;
        }
        $package = TemplatePackage::query()->where('zip_sha256', hash_file('sha256', $path))->first();
        if ($package === null) {
            $this->error('Ese .zip no es el de ningún paquete instalado (el sha256 no coincide).');

            return self::FAILURE;
        }

        $archive = new ZipArchive;
        if ($archive->open($path) !== true) {
            $this->error('No se pudo abrir el .zip.');

            return self::FAILURE;
        }
        // The package may sit in a folder of the zip; the capture is the one at preview/mobile.png.
        $png = null;
        for ($i = 0; $i < $archive->numFiles; $i++) {
            $stat = $archive->statIndex($i);
            if ($stat !== false && preg_match('#(^|/)preview/mobile\.png$#', $stat['name']) === 1 && $stat['size'] <= 800 * 1024) {
                $png = $archive->getFromIndex($i);
                break;
            }
        }
        $archive->close();

        $webp = is_string($png) ? $thumbnails->encode($png) : null;
        if ($webp === null) {
            $this->error('El paquete no trae un preview/mobile.png válido: no hay miniatura.');

            return self::FAILURE;
        }
        $thumbnails->publish($package->slug, $package->semver, $package->content_hash, $webp);
        $linked = $thumbnails->link($package, $package->status !== TemplatePackage::ACTIVE);
        $this->info("{$package->slug} {$package->semver}: miniatura creada ({$linked} plantilla(s) enlazada(s)).");

        return self::SUCCESS;
    }
}
