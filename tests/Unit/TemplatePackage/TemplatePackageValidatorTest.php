<?php

use Tests\Unit\TemplatePackage\PackageBuilder;

afterEach(fn () => PackageBuilder::cleanup());

/** Smallest PNG-like header that getimagesize() reads: signature + IHDR with the given size (a "pixel bomb" is tiny). */
function pngHeader(int $w, int $h): string
{
    return "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NN', $w, $h) . "\x08\x06\x00\x00\x00" . pack('N', 0) . pack('N', 0) . 'IEND' . pack('N', 0);
}

it('reports missing required files with their catalog codes', function (string $file, string $code) {
    $r = PackageBuilder::good()->without($file)->validate();
    expect($r['valid'])->toBeFalse()->and(PackageBuilder::errors($r))->toContain($code);
})->with([
    ['manifest.json', 'manifest_missing'],
    ['tokens.json', 'tokens_invalid'],
    ['sections.json', 'sections_invalid'],
    ['editable.json', 'editable_invalid'],
    ['LICENSES.md', 'license_missing'],
    ['preview/mobile.png', 'preview_missing'],
    ['preview/desktop.png', 'preview_missing'],
]);

it('rejects invalid manifests', function (callable $edit, string $code) {
    $r = PackageBuilder::good()->json('manifest.json', $edit)->validate();
    expect(PackageBuilder::errors($r))->toContain($code);
})->with([
    'slug uppercase' => [fn ($m) => [...$m, 'slug' => 'Botanical'], 'slug_invalid'],
    'slug with traversal' => [fn ($m) => [...$m, 'slug' => '../x'], 'slug_invalid'],
    'slug with quote (CSS scope injection)' => [fn ($m) => [...$m, 'slug' => 'a"],body{x:y}/*'], 'slug_invalid'],
    'slug too long' => [fn ($m) => [...$m, 'slug' => str_repeat('a', 40)], 'slug_invalid'],
    'version prerelease' => [fn ($m) => [...$m, 'version' => '1.0.0-beta'], 'version_invalid'],
    'version not semver' => [fn ($m) => [...$m, 'version' => 'latest'], 'version_invalid'],
    'level 2 with an unregistered slug' => [fn ($m) => [...$m, 'level' => 2], 'level2_slug_not_registered'],
    'level 3' => [fn ($m) => [...$m, 'level' => 3], 'level_not_allowed'],
    'engine too new' => [fn ($m) => [...$m, 'engineMin' => 99], 'engine_too_new'],
    'tier unknown' => [fn ($m) => [...$m, 'tier' => 'gold'], 'manifest_invalid'],
    'event type hidden' => [fn ($m) => [...$m, 'eventTypes' => ['birthday']], 'manifest_invalid'],
    'name with html' => [fn ($m) => [...$m, 'name' => ['es' => '<b>x</b>', 'en' => 'x']], 'manifest_invalid'],
    'name too long' => [fn ($m) => [...$m, 'name' => ['es' => str_repeat('a', 61), 'en' => 'x']], 'manifest_invalid'],
    'engineMin zero' => [fn ($m) => [...$m, 'engineMin' => 0], 'manifest_invalid'],
    'author url http' => [fn ($m) => [...$m, 'author' => ['name' => 'x', 'url' => 'javascript:alert(1)']], 'manifest_invalid'],
    'license without document' => [fn ($m) => [...$m, 'license' => ['id' => 'x', 'summary' => 'y']], 'license_missing'],
    'third party marks in a public package' => [fn ($m) => [...$m, 'thirdPartyMarks' => [['name' => 'ACME', 'owner' => 'ACME Inc', 'usage' => 'logo']]], 'third_party_marks_public'],
]);

it('lets a private package declare third party marks, with a warning', function () {
    $r = PackageBuilder::good()->json('manifest.json', fn ($m) => [...$m, 'visibility' => 'private', 'thirdPartyMarks' => [['name' => 'ACME', 'owner' => 'ACME Inc', 'usage' => 'logo']]])->validate();
    expect($r['valid'])->toBeTrue()->and(PackageBuilder::warnings($r))->toContain('third_party_marks_declared');
});

it('rejects malformed JSON documents, deep and huge', function () {
    $r = PackageBuilder::good()->with('tokens.json', '{"colors":')->validate();
    expect(PackageBuilder::errors($r))->toContain('tokens_invalid');

    $r = PackageBuilder::good()->with('manifest.json', str_repeat('[', 100) . str_repeat(']', 100))->validate();
    expect(PackageBuilder::errors($r))->toContain('manifest_invalid');

    $r = PackageBuilder::good()->with('editable.json', '{"fields":"' . bin2hex(random_bytes(35000)) . '"}')->validate();
    expect(PackageBuilder::errors($r))->toContain('file_too_large');
});

it('checks credits and licenses for every asset', function () {
    $r = PackageBuilder::good()->with('assets/img/extra.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><rect width="1" height="1"/></svg>')->validate();
    expect(PackageBuilder::errors($r))->toBe(['license_asset_missing']);

    $r = PackageBuilder::good()->json('manifest.json', function ($m) {
        $m['assetsCredits'][] = ['path' => 'assets/img/ghost.svg', 'title' => 'x', 'author' => 'x', 'source' => 'x', 'license' => 'x'];

        return $m;
    })->validate();
    expect(PackageBuilder::errors($r))->toBe(['license_asset_unknown']);
});

it('validates tokens: colors, fonts and self hosting', function (callable $edit, string $code) {
    $r = PackageBuilder::good()->json('tokens.json', $edit)->validate();
    expect(PackageBuilder::errors($r))->toContain($code);
})->with([
    'color not hex' => [fn ($t) => [...$t, 'colors' => [...$t['colors'], 'ink' => 'red']], 'color_invalid'],
    'color with css injection' => [fn ($t) => [...$t, 'colors' => [...$t['colors'], 'ink' => '#fff;} body{display:none']], 'color_invalid'],
    'color missing' => [function ($t) {
        unset($t['colors']['primary']);

        return $t;
    }, 'color_invalid'],
    'font from Google at runtime' => [fn ($t) => [...$t, 'fonts' => [...$t['fonts'], 'body' => ['family' => 'Lora', 'files' => []]]], 'font_not_self_hosted'],
    'font family with quote' => [fn ($t) => [...$t, 'fonts' => [...$t['fonts'], 'body' => ['family' => 'Lora"; src: url(//evil)', 'files' => []]]], 'tokens_invalid'],
    'font file missing' => [fn ($t) => [...$t, 'fonts' => [...$t['fonts'], 'body' => ['family' => 'Ghost', 'files' => [['file' => 'assets/fonts/ghost.woff2', 'weight' => 400, 'style' => 'normal']]]]], 'font_missing_file'],
    'font file outside fonts folder' => [fn ($t) => [...$t, 'fonts' => [...$t['fonts'], 'body' => ['family' => 'Ghost', 'files' => [['file' => '../x.woff2', 'weight' => 400, 'style' => 'normal']]]]], 'font_missing_file'],
    'unknown font role' => [fn ($t) => [...$t, 'fonts' => [...$t['fonts'], 'evil' => ['family' => 'x', 'files' => []]]], 'tokens_invalid'],
]);

it('validates sections.json and editable.json', function () {
    $r = PackageBuilder::good()->json('sections.json', function ($s) {
        array_pop($s['wedding']);
        array_pop($s['wedding']);

        return $s;
    })->validate();
    expect(PackageBuilder::errors($r))->toContain('sections_invalid');

    $r = PackageBuilder::good()->json('sections.json', function ($s) {
        // The cover must always be first: its order has to be lower than every other section's.
        $s['wedding'][0]['order'] = 9;

        return $s;
    })->validate();
    expect(PackageBuilder::errors($r))->toContain('sections_invalid');

    $r = PackageBuilder::good()->json('editable.json', function ($e) {
        $e['fields'][0]['bind'] = ['database' => 'users'];

        return $e;
    })->validate();
    expect(PackageBuilder::errors($r))->toContain('editable_bind_unknown');
});

it('validates preview images', function () {
    $r = PackageBuilder::good()->with('preview/mobile.png', pngHeader(100, 100))->validate();
    expect(PackageBuilder::errors($r))->toContain('preview_size');

    $r = PackageBuilder::good()->with('preview/mobile.png', 'not a png')->validate();
    expect(PackageBuilder::errors($r))->toContain('mime_mismatch');
});

it('rejects pixel bombs and out of range images without decoding them', function () {
    $r = PackageBuilder::good()->asset('assets/img/bomb.png', pngHeader(60000, 60000))->validate();
    expect(PackageBuilder::errors($r))->toContain('image_dimensions');

    $r = PackageBuilder::good()->asset('assets/img/bad.webp', "RIFF\x00\x00\x00\x00WEBPVP8 " . str_repeat("\0", 20))->validate();
    expect(array_intersect(PackageBuilder::errors($r), ['image_decode', 'image_dimensions']))->not->toBe([]);
});

it('runs the sanitizers over the package files and reports per file', function () {
    $r = PackageBuilder::good()->with('assets/img/wash.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><script>alert(1)</script></svg>')->validate();
    expect(PackageBuilder::errors($r))->toContain('svg_forbidden_element')
        ->and($r['report']['errors'][0]['file'])->toBe('assets/img/wash.svg');

    $r = PackageBuilder::good()->with('theme.css', ".a{background:url(https://evil/x.png)}\n.b{color:red !important}")->validate();
    expect(PackageBuilder::errors($r))->toContain('css_url_external')
        ->and($r['artifacts']['css'])->toBeNull();

    $r = PackageBuilder::good()->with('sections/cover.html', '<div onclick="alert(1)"><script>x</script></div>')->validate();
    expect(PackageBuilder::errors($r))->toContain('html_forbidden_tag');

    $r = PackageBuilder::good()->with('sections/foo.html', '<p>x</p>')->validate();
    expect(PackageBuilder::errors($r))->toContain('sections_invalid');

    $r = PackageBuilder::good()->with('sections/cover.json', '{}')->with('notes/readme.txt', 'x')->with('evil.css', 'a{}')->validate();
    expect(PackageBuilder::errors($r))->toContain('unexpected_file');
});

it('cross checks HTML and CSS: a stylesheet cannot hide the RSVP block or the card around it', function (string $css) {
    $r = PackageBuilder::good()->with('theme.css', $css)->validate();
    expect(PackageBuilder::errors($r))->toBe(['css_forbidden_property'])->and($r['artifacts']['css'])->toBeNull();
})->with([
    'class on the block' => ['.pkg-rsvp{display:none}'],
    'wrapper of the block' => ['.pkg-rsvp-wrap{display:none}'],
    'wrapper by id' => ['#pkg-rsvp-card{opacity:0}'],
    'wrapper clip' => ['.pkg-rsvp-wrap{clip-path:inset(100%)}'],
    'the language switch wrapper' => ['.pkg-top{pointer-events:none}'],
    'section hook' => ['[data-inv-section="rsvp"]{display:none}'],
    'system hook' => ['.inv-q--rsvp{visibility:hidden}'],
    'generic div descendant' => ['.pkg-frame div{display:none}'],
]);

it('rejects an id repeated across templates', function () {
    $r = PackageBuilder::good()->with('sections/closing.html', '<div id="pkg-rsvp-card">x</div>')->validate();
    expect(PackageBuilder::errors($r))->toBe(['html_duplicate_id']);
});

it('lets the same classes be styled visually', function () {
    $r = PackageBuilder::good()->with('theme.css', '.pkg-rsvp{background:var(--inv-surface-alt);border-radius:1rem}.pkg-rsvp-wrap{display:grid;padding:2rem}')->validate();
    expect($r['report']['errors'])->toBe([])->and($r['valid'])->toBeTrue();
});

it('warns about unused files and package weight', function () {
    $r = PackageBuilder::good()->asset('assets/img/unused.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><rect width="1" height="1"/></svg>')->validate();
    expect($r['valid'])->toBeTrue()->and(PackageBuilder::warnings($r))->toContain('unused_file');

    $png = PackageBuilder::noisyPng(340);
    $builder = PackageBuilder::good();
    $builder->asset('assets/img/big.png', $png);
    $r = $builder->validate();
    expect($r['valid'])->toBeTrue()->and(PackageBuilder::warnings($r))->toContain('budget_exceeded');
});

it('rejects packages over the runtime budget', function () {
    $builder = PackageBuilder::good();
    $blob = PackageBuilder::noisyPng(340);
    foreach (range(1, 3) as $i) {
        $builder->asset("assets/img/blob{$i}.png", $blob);
    }
    // 3 x 390 KB stays under 1 MB; add a fourth to cross it
    $builder->asset('assets/img/blob4.png', $blob);
    $r = $builder->validate();
    expect(PackageBuilder::errors($r))->toContain('package_too_large');
});

it('is robust against hostile content in every path: no exception ever escapes the validator', function () {
    mt_srand(7);
    $good = PackageBuilder::good();
    $targets = ['manifest.json', 'tokens.json', 'sections.json', 'editable.json', 'theme.css', 'layout.html', 'sections/cover.html', 'sections/rsvp.html', 'assets/img/wash.svg'];
    for ($i = 0; $i < 60; $i++) {
        $b = clone $good;
        $path = $targets[array_rand($targets)];
        $bytes = $b->files[$path];
        // flip, truncate or inject random bytes into one file
        $mode = mt_rand(0, 2);
        if ($mode === 0) {
            $pos = mt_rand(0, max(0, strlen($bytes) - 1));
            $bytes = substr_replace($bytes, random_bytes(mt_rand(1, 8)), $pos, mt_rand(0, 5));
        } elseif ($mode === 1) {
            $bytes = substr($bytes, 0, mt_rand(0, strlen($bytes)));
        } else {
            $bytes = str_repeat($bytes, 2) . '<' . random_bytes(5) . '{{';
        }
        $b->files[$path] = $bytes;
        $r = $b->validate();
        expect($r)->toHaveKeys(['valid', 'report', 'artifacts']);
    }
});
