<?php

namespace App\Console\Commands;

use App\Http\Controllers\TemplateAssetController;
use App\Http\Services\Admin\Templates\Packages\MissingPackagesUrl;
use App\Http\Services\Admin\Templates\Packages\PackageFiles;
use App\Models\TemplatePackage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Recomputes the sha256 of every published file of every installed package against what was recorded when it was
 * built (spec 4.6): a difference means the storage was tampered with (or lost files). With `--probe` it also asks the
 * real origin for one file of each package and checks the headers of spec 5.2, which is what turns "the CDN is set up"
 * from an assumption into a verified fact.
 */
class VerifyTemplatePackages extends Command
{
    protected $signature = 'templates:verify-packages {--probe : Also request one css and one asset of each package from the public origin and check its headers}';

    protected $description = 'Verify the hashes of the installed template package files (and, with --probe, the headers of the origin)';

    public function handle(PackageFiles $files): int
    {
        $bad = 0;
        $packages = TemplatePackage::query()->where('status', '!=', TemplatePackage::WITHDRAWN)->orderBy('slug')->orderBy('id')->get();

        foreach ($packages as $package) {
            $problems = $files->differences((string) $package->storage_path, (array) $package->files);
            if ($this->option('probe')) {
                array_push($problems, ...$this->probe($files, $package));
            }
            if ($problems === []) {
                $this->line("ok    {$package->slug} {$package->semver}");

                continue;
            }
            $bad++;
            $this->error("FAIL  {$package->slug} {$package->semver}: " . implode('; ', array_slice($problems, 0, 10)));
        }
        $this->info(count($packages) . " package(s) checked, {$bad} with problems.");

        return $bad > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return list<string> */
    private function probe(PackageFiles $files, TemplatePackage $package): array
    {
        $problems = [];
        $paths = collect((array) $package->files)->where('published', true)->pluck('path');
        $samples = array_filter([$paths->first(fn ($p) => $p === 'theme.css'), $paths->first(fn ($p) => str_starts_with($p, 'assets/'))]);
        $origin = (string) (TemplateAssetController::allowedOrigins()[0] ?? '');

        foreach ($samples as $path) {
            try {
                $url = $files->url("{$package->storage_path}/{$path}");
            } catch (MissingPackagesUrl $e) {
                return [$e->getMessage()];
            }
            $response = Http::timeout(10)->withHeaders($origin !== '' ? ['Origin' => $origin] : [])->get($url);
            if (! $response->successful()) {
                $problems[] = "{$path}: {$url} answered {$response->status()}";

                continue;
            }
            $expect = PackageFiles::mime($path);
            if (strtolower((string) $response->header('Content-Type')) !== strtolower($expect)) {
                $problems[] = "{$path}: Content-Type is «{$response->header('Content-Type')}», expected «{$expect}»";
            }
            if (strtolower((string) $response->header('X-Content-Type-Options')) !== 'nosniff') {
                $problems[] = "{$path}: missing X-Content-Type-Options: nosniff";
            }
            if (! str_contains((string) $response->header('Content-Security-Policy'), 'sandbox')) {
                $problems[] = "{$path}: Content-Security-Policy without sandbox";
            }
            if (! str_contains((string) $response->header('Cache-Control'), 'immutable')) {
                $problems[] = "{$path}: Cache-Control is not immutable";
            }
            if ($origin !== '' && $response->header('Access-Control-Allow-Origin') !== $origin) {
                $problems[] = "{$path}: Access-Control-Allow-Origin is not {$origin}";
            }
            if ($response->header('Set-Cookie') !== null && $response->header('Set-Cookie') !== '') {
                $problems[] = "{$path}: the origin sets cookies";
            }
        }

        return $problems;
    }
}
