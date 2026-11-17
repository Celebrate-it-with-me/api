<?php

use App\Support\TemplatePackage\BlockCatalog;
use App\Support\TemplatePackage\CssSanitizer;
use App\Support\TemplatePackage\HtmlAstBuilder;
use App\Support\TemplatePackage\Report;
use App\Support\TemplatePackage\ReportCodes;
use App\Support\TemplatePackage\ValidationContext;
use Tests\Unit\TemplatePackage\PackageBuilder;

/** Builds one section with the given string keys declared. @return array{0: ?array, 1: list<string>, 2: Report} */
function contractAst(string $html, array $strings = ['invitedFor'], string $type = 'cover'): array
{
    $report = new Report;
    $out = (new HtmlAstBuilder)->withStrings($strings)->build($html, "sections/{$type}.html", 'section', $type, ['assets/img/a.webp'], $report);

    return [$out, $report->errorCodes(), $report];
}

// --- the classes and ids of a package ------------------------------------------------------------------------------------

it('only lets a package use pkg- classes (Tailwind utilities and engine classes are refused)', function (string $class) {
    [$out, $codes] = contractAst('<div class="' . $class . '">x</div>');
    expect($out)->toBeNull()->and($codes)->toContain('html_attr_invalid');
})->with(['fixed', 'fixed inset-0', 'pkg-ok inv-rsvp', 'card', 'Pkg-A', 'pkg-', 'pkg-' . 'a b/c']);

it('accepts pkg- classes', function () {
    [$out, $codes] = contractAst('<div class="pkg-a pkg-b_c pkg-9">x</div>');
    expect($codes)->toBe([])->and($out['ast']['nodes'][0]['attrs']['class'])->toBe('pkg-a pkg-b_c pkg-9');
});

// --- the conditional node -----------------------------------------------------------------------------------------------

it('turns data-if into an if node that wraps the element', function () {
    [$out, $codes] = contractAst('<p class="pkg-t" data-if="section.text">{{ section.text }}</p>');
    expect($codes)->toBe([])
        ->and($out['ast']['nodes'][0])->toMatchArray(['t' => 'if', 'path' => 'section.text'])
        ->and($out['ast']['nodes'][0]['children'][0])->toMatchArray(['t' => 'el', 'tag' => 'p'])
        ->and($out['ast']['nodes'][0]['children'][0]['attrs'])->not->toHaveKey('data-if')
        ->and($out['vars'])->toContain('section.text');
});

it('refuses data-if on or around a system block, with expressions or unknown variables', function (string $html, string $code) {
    [$out, $codes] = contractAst($html);
    expect($out)->toBeNull()->and($codes)->toContain($code);
})->with([
    'on a block' => ['<q-names data-if="event.name"></q-names>', 'behavior_scope'],
    'around a block' => ['<div data-if="event.name"><q-date></q-date></div>', 'behavior_scope'],
    'with a filter' => ['<p data-if="event.name|upper">x</p>', 'var_invalid'],
    'with an expression' => ['<p data-if="event.name == 1">x</p>', 'var_invalid'],
    'unknown variable' => ['<p data-if="guest.email">x</p>', 'var_unknown'],
    'section variable in a layout-only context is checked elsewhere' => ['<p data-if="t.nope">x</p>', 'var_unknown'],
]);

// --- variables -----------------------------------------------------------------------------------------------------------

it('resolves t.* against the keys of strings.json and theme.* from the closed context', function () {
    [$out, $codes] = contractAst('<p>{{ t.invitedFor }} {{ theme.preset|upper }} {{ event.date|time }}</p>');
    expect($codes)->toBe([])->and($out['vars'])->toEqualCanonicalizing(['t.invitedFor', 'theme.preset', 'event.date']);

    [$none, $noneCodes] = contractAst('<p>{{ t.missing }}</p>');
    expect($none)->toBeNull()->and($noneCodes)->toContain('var_unknown');

    [$noStrings, $codes2] = contractAst('<p>{{ t.invitedFor }}</p>', []);
    expect($noStrings)->toBeNull()->and($codes2)->toContain('var_unknown');
});

it('uses the unified filter names of the server', function (string $expr, bool $ok) {
    [$out, $codes] = contractAst("<p>{{ {$expr} }}</p>");
    expect($out !== null)->toBe($ok);
})->with([
    ['event.name|upper', true], ['event.name|lower', true], ['event.name|capitalize', true],
    ['event.date|long', true], ['event.date|short', true], ['event.date|weekday', true], ['event.date|weekday_short', true],
    ['event.date|day', true], ['event.date|month', true], ['event.date|month_short', true], ['event.date|year', true],
    ['event.date|upper_month', true], ['event.date|time', true],
    ['event.date|weekdayShort', false], ['event.name|reverse', false], ['rsvp.seats|upper', false],
]);

// --- the 21 blocks -------------------------------------------------------------------------------------------------------

it('has the 22 blocks of the catalog, including closing, save-the-date, comments, tables and suggested-music', function () {
    expect(array_keys(BlockCatalog::BLOCKS))->toHaveCount(22)->toContain('closing', 'save-the-date', 'comments', 'tables', 'suggested-music');
});

it('accepts the frame prop on the blocks that draw a section', function () {
    [$out, $codes] = contractAst('<q-closing frame="bare" variant="plain"></q-closing><q-save-the-date frame="no-title" calendar="false"></q-save-the-date><q-gallery frame="full"></q-gallery>');
    expect($codes)->toBe([])->and($out['blocks'])->toMatchArray(['closing' => 1, 'save-the-date' => 1, 'gallery' => 1]);

    [$bad, $badCodes] = contractAst('<q-closing frame="huge"></q-closing>');
    expect($bad)->toBeNull()->and($badCodes)->toContain('block_prop_invalid');
});

it('warns when headings skip a level', function () {
    [$out, , $report] = contractAst('<h2>a</h2><h4>b</h4>');
    expect($out)->not->toBeNull()->and($report->warningCodes())->toBe(['heading_order']);
});

// --- the package as a whole ----------------------------------------------------------------------------------------------

it('treats slots.json as an unexpected file (slots were retired)', function () {
    $r = PackageBuilder::good()->with('slots.json', '{}')->validate();
    expect(PackageBuilder::errors($r))->toBe(['unexpected_file']);
});

it('validates strings.json', function (callable $edit, string $code) {
    $r = PackageBuilder::good()->json('strings.json', $edit)->validate();
    expect(PackageBuilder::errors($r))->toContain($code);
})->with([
    'language missing' => [fn ($s) => ['es' => $s['es']], 'strings_invalid'],
    'different keys' => [fn ($s) => [...$s, 'en' => ['other' => 'x']], 'strings_invalid'],
    'html in a text' => [fn ($s) => [...$s, 'es' => [...$s['es'], 'invitedFor' => '<b>x</b>']], 'strings_invalid'],
    'braces in a text' => [fn ($s) => [...$s, 'es' => [...$s['es'], 'invitedFor' => '{{ event.name }}']], 'strings_invalid'],
    'bad key' => [fn ($s) => ['es' => ['Bad-Key' => 'x'], 'en' => ['Bad-Key' => 'x']], 'strings_invalid'],
]);

it('rejects a t.* variable that strings.json does not declare', function () {
    $r = PackageBuilder::good()->without('strings.json')->validate();
    expect(PackageBuilder::errors($r))->toBe(['var_unknown']);
});

it('returns the sanitized artifacts the installer needs', function () {
    $r = PackageBuilder::good()->validate();
    expect($r['valid'])->toBeTrue()
        ->and(array_keys($r['artifacts']['json']))->toEqualCanonicalizing(['manifest', 'tokens', 'sections', 'editable', 'strings'])
        ->and(array_keys($r['artifacts']['blobs']))->toEqualCanonicalizing(['assets/img/paper.webp', 'assets/fonts/script-400.woff2'])
        ->and($r['report']['stats'])->toHaveKeys(['files', 'runtimeBytes', 'firstFoldBytes']);
});

// --- context-dependent codes ---------------------------------------------------------------------------------------------

it('knows the world outside the zip through the validation context', function (callable $context, string $code) {
    $r = PackageBuilder::good()->validate($context());
    expect(PackageBuilder::errors($r))->toContain($code);
})->with([
    'engine too new' => [fn () => new ValidationContext(engine: 0), 'engine_too_new'],
    'slug taken by another level' => [fn () => new ValidationContext(levels: ['botanical' => 2]), 'slug_taken'],
    'visibility changed' => [fn () => new ValidationContext(visibilities: ['botanical' => 'private']), 'visibility_changed'],
    'version not greater' => [fn () => new ValidationContext(installed: ['botanical' => '1.0.0']), 'version_not_greater'],
]);

it('lets a registered level 2 slug through', function () {
    $r = PackageBuilder::good()->json('manifest.json', fn ($m) => [...$m, 'level' => 2])->validate(new ValidationContext(level2Slugs: ['botanical']));
    expect(PackageBuilder::errors($r))->toBe([]);
});

it('warns about fields and variant values removed since the previous version', function () {
    $context = new ValidationContext(previous: ['botanical' => ['fields' => ['cover_photo', 'color_primary', 'gone'], 'variants' => ['cover.layout' => ['arch', 'bleed']]]]);
    $r = PackageBuilder::good()->json('manifest.json', fn ($m) => [...$m, 'variants' => ['cover.layout' => ['values' => ['arch'], 'default' => 'arch']]])->validate($context);
    expect($r['valid'])->toBeTrue()->and(PackageBuilder::warnings($r))->toContain('field_removed', 'variant_removed');
});

it('warns about text colors without contrast', function () {
    $r = PackageBuilder::good()->json('tokens.json', function ($t) {
        $t['colors']['inkSoft'] = '#f0eee0';
        $t['presets']['salvia']['colors']['inkSoft'] = '#f0eee0';

        return $t;
    })->validate();
    expect($r['valid'])->toBeTrue()->and(PackageBuilder::warnings($r))->toContain('contrast_low');
});

it('validates the options a package defines and the editable fields that bind them', function (callable $edit, string $code) {
    $r = PackageBuilder::good()
        ->json('manifest.json', fn ($m) => [...$m, 'variants' => ['cover.layout' => ['values' => ['arch', 'bleed'], 'default' => 'bleed']]])
        ->json('editable.json', $edit)->validate();
    expect(PackageBuilder::errors($r))->toContain($code);
})->with([
    'choice with a value the variant does not have' => [function ($e) {
        $e['fields'][] = ['id' => 'layout', 'type' => 'choice', 'label' => ['es' => 'a', 'en' => 'a'], 'bind' => ['option' => 'cover.layout'], 'choices' => [['value' => 'wide', 'label' => ['es' => 'a', 'en' => 'a']]]];

        return $e;
    }, 'editable_bind_unknown'],
    'choice bound to an option that is not declared' => [function ($e) {
        $e['fields'][] = ['id' => 'layout', 'type' => 'choice', 'label' => ['es' => 'a', 'en' => 'a'], 'bind' => ['option' => 'nope'], 'choices' => [['value' => 'arch', 'label' => ['es' => 'a', 'en' => 'a']]]];

        return $e;
    }, 'editable_bind_unknown'],
    'color bound to a text section' => [function ($e) {
        $e['fields'][] = ['id' => 'x', 'type' => 'color', 'label' => ['es' => 'a', 'en' => 'a'], 'bind' => ['section' => 'cover', 'field' => 'text']];

        return $e;
    }, 'editable_bind_unknown'],
    'unknown group' => [function ($e) {
        $e['fields'][0]['group'] = 'nope';

        return $e;
    }, 'editable_invalid'],
    'label without english' => [function ($e) {
        $e['fields'][0]['label'] = ['es' => 'x'];

        return $e;
    }, 'editable_invalid'],
]);

it('rejects a variant declared with a default outside its values', function () {
    $r = PackageBuilder::good()->json('manifest.json', fn ($m) => [...$m, 'variants' => ['cover.layout' => ['values' => ['a'], 'default' => 'b']]])->validate();
    expect(PackageBuilder::errors($r))->toContain('variant_unknown');
});

it('checks the tokens beyond the six colors', function (callable $edit) {
    $r = PackageBuilder::good()->json('tokens.json', $edit)->validate();
    expect(PackageBuilder::errors($r))->not->toBe([]);
})->with([
    'preset that does not exist' => [fn ($t) => [...$t, 'preset' => 'nope']],
    'no presets' => [fn ($t) => [...$t, 'presets' => []]],
    'a preset with a bad color' => [function ($t) {
        $t['presets']['salvia']['colors']['ink'] = 'red';

        return $t;
    }],
    'font weight out of range' => [function ($t) {
        $t['fonts']['script']['files'][0]['weight'] = 450;

        return $t;
    }],
    'unknown color' => [function ($t) {
        $t['colors']['hot'] = '#fff';

        return $t;
    }],
]);

it('lets only a free package carry at most three presets', function () {
    $r = PackageBuilder::good()->json('manifest.json', fn ($m) => [...$m, 'tier' => 'free'])->json('tokens.json', function ($t) {
        foreach (['a', 'b', 'c'] as $id) {
            $t['presets'][$id] = $t['presets']['salvia'];
        }

        return $t;
    })->validate();
    expect(PackageBuilder::errors($r))->toBe(['tokens_invalid']);
});

it('checks the real content of images and fonts, not only their extension', function () {
    $png = PackageBuilder::good()->asset('assets/img/fake.webp', file_get_contents(__DIR__ . '/../../Fixtures/TemplatePackage/good/preview/mobile.png'))->validate();
    expect(PackageBuilder::errors($png))->toBe(['mime_mismatch']);

    $font = PackageBuilder::good()->with('assets/fonts/script-400.woff2', 'wOF2' . "\x00\x01\x00\x00" . pack('N', 9999) . str_repeat('x', 60))->validate();
    expect(PackageBuilder::errors($font))->toBe(['woff2_invalid']);
});

it('reads --inv-* variables from the CSS but never declares them', function () {
    $ok = PackageBuilder::good()->with('theme.css', '.pkg-hero{color:var(--inv-ink);background:var(--inv-wash)}')->validate();
    expect($ok['valid'])->toBeTrue();

    $bad = PackageBuilder::good()->with('theme.css', ':root{--inv-ink:red}')->validate();
    expect(PackageBuilder::errors($bad))->toContain('css_inv_var');
});

it('allows SVG filters for the watercolor and paper textures, with a cost warning', function () {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><filter id="w"><feTurbulence baseFrequency="0.02" numOctaves="3"/><feDisplacementMap in="SourceGraphic" scale="40"/></filter><rect width="10" height="10" filter="url(#w)"/></svg>';
    $r = PackageBuilder::good()->asset('assets/img/wash2.svg', $svg)->validate();
    expect(PackageBuilder::errors($r))->toBe([])->and(PackageBuilder::warnings($r))->toContain('css_costly_property');
});

// --- the catalog of codes ------------------------------------------------------------------------------------------------

it('lists every report code the sources can emit', function () {
    $known = [...ReportCodes::ERRORS, ...ReportCodes::WARNINGS];
    $used = [];
    foreach (glob(base_path('app/Support/TemplatePackage/*.php')) as $file) {
        if (str_ends_with($file, 'ReportCodes.php')) {
            continue;
        }
        preg_match_all('/->(?:error|warning)\([^,]+,\s*\'([a-z0-9_]+)\'/', file_get_contents($file), $m);
        array_push($used, ...$m[1]);
        // codes returned by a helper: `$code = ... ? 'a' : 'b'` and match arms
        preg_match_all("/'((?:zip|css|svg|html|block|behavior|var|img|font|license|slug|version|manifest|tokens|sections|editable|preview|image|name|ext|mime|file|package|woff2|strings|level|engine|unexpected|layout|section|third|visibility|variant|empty|unused|budget|first|field|ignored|contrast|heading)_[a-z0-9_]+)'/", file_get_contents($file), $n);
        array_push($used, ...$n[1]);
    }
    expect(array_values(array_diff(array_unique($used), $known, ['zip_bomb_x'])))->toBe([])
        ->and(array_intersect(ReportCodes::ERRORS, ReportCodes::WARNINGS))->toBe([]);
});

// --- the system hooks a stylesheet may target -------------------------------------------------------------------------------

it('lets the CSS target the closed list of system hooks, with visual properties only', function (string $selector, bool $ok) {
    $report = new Report;
    $out = (new CssSanitizer)->sanitize("{$selector}{color:red}", 'botanical', 'theme.css', $report, [], [], []);
    expect($out !== null)->toBe($ok);
})->with([
    ['.inv-q--cover-photo', true], ['.inv-q--rsvp', true], ['.inv-q--save-the-date', true], ['.inv-q-photo__emblem', true],
    ['.pkg-a .inv-q--date', true], ['[data-inv-parity="even"]', true], ['.inv-root', true],
    ['.inv-q--nope', false], ['.inv-rsvp', false], ['.inv-section', false], ['.inv-q-photo__', false], ['.inv-button', false], ['.inv-watermark', false],
]);

it('keeps the hooks of the system visual-only (no hiding of a system block)', function () {
    $report = new Report;
    $out = (new CssSanitizer)->sanitize('.inv-q--rsvp{display:none}', 'botanical', 'theme.css', $report, [], [], []);
    expect($out)->toBeNull()->and($report->errorCodes())->toContain('css_forbidden_property');
});

// --- what the real packages of the app needed (Clásica and botánica of dev-packages) ----------------------------------------

it('accepts logical properties, text in --pkg-* variables and decoration with pseudo-elements around a system block', function (string $css) {
    $report = new Report;
    $out = (new CssSanitizer)->sanitize($css, 'classic', 'theme.css', $report, ['assets/m.svg'], [], ['pkg-card' => 'wrapper', 'pkg-photo' => 'system']);
    expect($report->errorCodes())->toBe([])->and($out)->not->toBeNull();
})->with([
    ['.pkg-a{margin-inline:auto;padding-block:1rem;border-block:1px solid red}'],
    ['.pkg-a{--pkg-script:var(--inv-font-script,"Pinyon Script","Snell Roundhand",cursive)}'],
    ['.pkg-card::before{content:"";position:absolute;inset:-1px;pointer-events:none}'],
    ['.pkg-x .inv-q--cover-photo{width:12rem;height:16rem;display:flex;align-items:center;mask-image:url(assets/m.svg);border-radius:9999px 9999px 0 0}'],
    ['.pkg-body>*{position:relative}'],
]);

it('still refuses to hide or move what the same relaxations touch', function (string $css) {
    $report = new Report;
    $out = (new CssSanitizer)->sanitize($css, 'classic', 'theme.css', $report, ['assets/m.svg'], [], ['pkg-card' => 'wrapper']);
    expect($out)->toBeNull()->and(array_intersect($report->errorCodes(), ['css_forbidden_property', 'state_selector_property', 'block_container_property']))->not->toBeEmpty();
})->with([
    ['.pkg-card{pointer-events:none}'],
    ['.pkg-card:hover{pointer-events:none}'],
    ['.pkg-body>*{position:absolute}'],
    ['.inv-q--cover-photo{display:none}'],
    ['.inv-q--cover-photo{width:0}'],
    ['.inv-q--cover-photo{pointer-events:none}'],
    ['.inv-q--cta{width:12rem}'],
    ['.inv-q--rsvp{mask-image:url(assets/m.svg)}'],
    ['.pkg-a::before{content:var(--pkg-x)}'],
]);
