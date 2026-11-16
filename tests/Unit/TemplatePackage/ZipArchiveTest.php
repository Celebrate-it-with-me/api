<?php

use App\Support\TemplatePackage\PackageArchive;
use App\Support\TemplatePackage\Report;
use Tests\Unit\TemplatePackage\PackageBuilder;

afterEach(fn () => PackageBuilder::cleanup());

function readZip(array $files, array $meta = []): array
{
    $base = tempnam(sys_get_temp_dir(), 'pkg');
    $path = $base . '.zip';
    file_put_contents($path, PackageBuilder::raw($files, $meta));
    $report = new Report;
    $out = (new PackageArchive)->read($path, $report);
    @unlink($path);
    @unlink($base);

    return [$out, $report->errorCodes(), $report->warningCodes()];
}

it('rejects hostile entry names', function (string $name, string $code) {
    [$files, $codes] = readZip(['manifest.json' => '{}', $name => 'x']);
    expect($codes)->toContain($code)
        ->and(array_keys($files ?? []))->not->toContain($name);
})->with([
    'parent traversal' => ['../evil.json', 'zip_path_traversal'],
    'nested traversal' => ['assets/../../evil.json', 'zip_path_traversal'],
    'dot segment' => ['assets/./a.json', 'zip_path_traversal'],
    'absolute' => ['/etc/passwd.json', 'zip_absolute_path'],
    'windows drive' => ['C:evil.json', 'zip_absolute_path'],
    'backslash separator' => ['assets\\a.json', 'zip_path_traversal'],
    'backslash traversal' => ['..\\..\\evil.json', 'zip_path_traversal'],
    'NUL in name' => ["assets/a\0.json", 'name_invalid'],
    'uppercase' => ['Assets/A.json', 'name_invalid'],
    'space' => ['assets/my file.json', 'name_invalid'],
    'cyrillic confusable' => ["assets/m\u{0430}nifest.json", 'name_invalid'],
    'right-to-left override' => ["assets/a\u{202E}nosj.json", 'name_invalid'],
    'invalid UTF-8' => ["assets/\xFF\xFE.json", 'name_invalid'],
    'too deep' => ['a/b/c/d/e/f/g.json', 'name_invalid'],
    'too long' => [str_repeat('a', 130) . '.json', 'name_invalid'],
    'hidden dotfile' => ['assets/.htaccess', 'name_invalid'],
]);

it('rejects symlinks and special files by unix mode', function (int $mode) {
    [, $codes] = readZip(['manifest.json' => '{}', 'assets/link.svg' => '/etc/passwd'], ['assets/link.svg' => ['mode' => $mode]]);
    expect($codes)->toContain('zip_symlink');
})->with(['symlink' => [0120777], 'block device' => [0060644], 'fifo' => [0010644], 'socket' => [0140644]]);

it('rejects encrypted entries', function () {
    [, $codes] = readZip(['manifest.json' => '{}', 'assets/a.svg' => 'x'], ['assets/a.svg' => ['flags' => 1]]);
    expect($codes)->toContain('zip_encrypted');
});

it('rejects forbidden extensions and nested archives', function () {
    [, $codes] = readZip([
        'manifest.json' => '{}',
        'assets/run.php' => '<?php',
        'assets/x.js' => 'alert(1)',
        'assets/inner.zip' => PackageBuilder::raw(['a.json' => '{}']),
        'assets/photo.png' => PackageBuilder::raw(['a.json' => '{}']), // zip bytes hidden behind an image extension
    ]);
    expect($codes)->toContain('ext_not_allowed')->toContain('zip_nested');
});

it('rejects entries whose content does not match the extension', function () {
    $png = file_get_contents(__DIR__ . '/../../Fixtures/TemplatePackage/good/preview/mobile.png');
    [, $codes] = readZip(['manifest.json' => '{}', 'assets/a.webp' => $png, 'assets/b.svg' => "\x89PNG\r\n\x1a\n" . str_repeat("\0", 20), 'assets/c.woff2' => 'not a font at all, no signature, but long enough to pass the size check....']);
    expect($codes)->toContain('mime_mismatch')->toContain('woff2_invalid');
});

it('rejects names that differ only by case (the uppercase one fails the name rule)', function () {
    [, $codes] = readZip(['manifest.json' => '{}', 'assets/a.svg' => '<svg/>', 'assets/A.svg' => '<svg/>']);
    expect($codes)->toContain('name_invalid');
});

it('rejects duplicate entries with the very same name', function () {
    $path = tempnam(sys_get_temp_dir(), 'pkg');
    // Raw writer takes an assoc array, so build two entries with the same name by hand.
    $a = PackageBuilder::raw(['assets/a.svg' => '<svg/>']);
    $one = substr($a, 0, strpos($a, "PK\x01\x02"));
    $central = substr($a, strpos($a, "PK\x01\x02"), -22);
    $offset2 = strlen($one);
    $central2 = substr_replace($central, pack('V', $offset2), 42, 4);
    $body = $one . $one;
    $end = pack('VvvvvVVv', 0x06054B50, 0, 0, 2, 2, strlen($central) * 2, strlen($body), 0);
    file_put_contents($path, $body . $central . $central2 . $end);
    $report = new Report;
    (new PackageArchive)->read($path, $report);
    @unlink($path);
    expect($report->errorCodes())->toContain('name_duplicate');
});

it('rejects too many entries', function () {
    $files = ['manifest.json' => '{}'];
    for ($i = 0; $i < 130; $i++) {
        $files["assets/f{$i}.svg"] = '<svg/>';
    }
    [$out, $codes] = readZip($files);
    expect($codes)->toContain('zip_too_many_files')->and($out)->toBeNull();
});

it('rejects an enormous entry count before iterating (hard cap)', function () {
    $files = [];
    for ($i = 0; $i < 700; $i++) {
        $files["a{$i}.txt"] = 'x';
    }
    [$out, $codes] = readZip($files);
    expect($codes)->toContain('zip_too_many_files')->and($out)->toBeNull();
});

it('rejects a compression bomb by ratio', function () {
    [, $codes] = readZip(['manifest.json' => '{}', 'assets/zero.svg' => str_repeat("\0", 5_000_000)]);
    expect($codes)->toContain('zip_bomb');
});

it('rejects a zip whose headers lie about the size (real bytes read in streaming with a cap)', function () {
    // 8 MB of zeros really inside the stream, headers claim 1000 bytes, so the declared ratio looks harmless.
    $real = str_repeat("\0", 8_000_000);
    $deflated = gzdeflate($real, 9);
    [$files, $codes] = readZip(['manifest.json' => '{}', 'assets/big.svg' => $real], ['assets/big.svg' => ['declared' => 1000, 'raw' => $deflated]]);
    expect($codes)->toContain('zip_bomb')
        ->and(array_keys($files ?? []))->not->toContain('assets/big.svg');
});

it('rejects an uncompressed total over the limit with a modest ratio', function () {
    $chunk = random_bytes(20_000);
    $files = ['manifest.json' => '{}'];
    for ($i = 0; $i < 16; $i++) {
        // 800 KB of a repeated random block: ratio about 30:1, each file under the raster cap, 12.8 MB in total.
        $files["assets/n{$i}.png"] = str_repeat($chunk, 40);
    }
    [, $codes] = readZip($files);
    expect($codes)->toContain('zip_bomb');
});

it('rejects empty, truncated and non zip uploads', function () {
    $empty = tempnam(sys_get_temp_dir(), 'pkg');
    $report = new Report;
    expect((new PackageArchive)->read($empty, $report))->toBeNull()->and($report->errorCodes())->toBe(['not_a_zip']);

    file_put_contents($empty, '<?php echo 1;');
    $report = new Report;
    expect((new PackageArchive)->read($empty, $report))->toBeNull()->and($report->errorCodes())->toBe(['not_a_zip']);

    file_put_contents($empty, substr(PackageBuilder::raw(['a.json' => '{}']), 0, 40));
    $report = new Report;
    expect((new PackageArchive)->read($empty, $report))->toBeNull()->and($report->errorCodes())->toBe(['not_a_zip']);
    @unlink($empty);
});

it('rejects a zip over the size limit', function () {
    $path = tempnam(sys_get_temp_dir(), 'pkg');
    file_put_contents($path, "PK\x03\x04" . random_bytes(8_500_000));
    $report = new Report;
    expect((new PackageArchive)->read($path, $report))->toBeNull()->and($report->errorCodes())->toBe(['package_too_large']);
    @unlink($path);
});

it('warns and ignores operating system litter', function () {
    [$files, $codes, $warnings] = readZip(['manifest.json' => '{}', '__MACOSX/._manifest.json' => 'x', 'assets/.DS_Store' => 'x']);
    expect($warnings)->toContain('ignored_file')->and($codes)->toBe([])->and(array_keys($files))->toBe(['manifest.json']);
});

it('flags a package wrapped in a single folder', function () {
    [, $codes] = readZip(['botanical/manifest.json' => '{}', 'botanical/tokens.json' => '{}']);
    expect($codes)->toContain('unexpected_root');
});

it('never writes anything to disk while reading', function () {
    $path = tempnam(sys_get_temp_dir(), 'pkg');
    file_put_contents($path, PackageBuilder::raw(['manifest.json' => '{}', 'assets/a.svg' => '<svg/>']));
    $before = glob(sys_get_temp_dir() . '/*');
    (new PackageArchive)->read($path, new Report);
    $after = glob(sys_get_temp_dir() . '/*');
    @unlink($path);
    expect(array_diff($after, $before))->toBe([]);
});
