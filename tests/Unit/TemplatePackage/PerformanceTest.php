<?php

use App\Support\TemplatePackage\TemplatePackageValidator;
use Tests\Unit\TemplatePackage\PackageBuilder;

afterEach(fn () => PackageBuilder::cleanup());

it('validates a 1 MB package with 100+ files within budget', function () {
    $builder = PackageBuilder::heavy();
    $zip = $builder->zip();

    $before = memory_get_peak_usage(true);
    $start = microtime(true);
    $result = (new TemplatePackageValidator)->validate($zip);
    $elapsed = microtime(true) - $start;

    fwrite(STDERR, sprintf(
        "\n[perf] files=%d zip=%.0fKB uncompressed=%.0fKB css=%.0fKB time=%.0fms peakMemory=%.1fMB (delta %.1fMB)\n",
        $result['report']['stats']['files'] ?? 0,
        filesize($zip) / 1024,
        ($result['report']['stats']['uncompressedBytes'] ?? 0) / 1024,
        strlen($builder->files['theme.css']) / 1024,
        $elapsed * 1000,
        memory_get_peak_usage(true) / 1048576,
        (memory_get_peak_usage(true) - $before) / 1048576
    ));

    expect($result['report']['errors'])->toBe([])
        ->and($result['valid'])->toBeTrue()
        ->and($result['report']['stats']['files'])->toBeGreaterThanOrEqual(100)
        ->and($result['report']['stats']['uncompressedBytes'])->toBeGreaterThan(900_000)
        ->and($elapsed)->toBeLessThan(5.0);
});
