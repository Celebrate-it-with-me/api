<?php

use App\Support\TemplatePackage\CssSanitizer;
use App\Support\TemplatePackage\Report;
use Tests\Unit\TemplatePackage\PackageBuilder;

/**
 * S8: the facts the engine publishes on the invitation root (`data-inv-opt-<id>`, `data-inv-name-len`,
 * `data-inv-guest-len`, `data-inv-has-photo`) may be read by a package stylesheet only as a root condition of a rule about
 * its own `pkg-*` elements. Nothing else of the `data-inv-opt-*` / `-name-*` / `-guest-*` / `-has-*` namespace passes.
 */
afterEach(fn () => PackageBuilder::cleanup());

const FACT_KINDS = ['pkg-w' => 'wrapper', 'pkg-rsvp' => 'system'];
const FACT_OPTIONS = ['cover_layout' => ['arch', 'hero'], 'tone' => ['soft', 'bold']];

/** @return array{0: ?string, 1: list<string>, 2: Report} */
function factcss(string $source, array $options = FACT_OPTIONS): array
{
    $report = new Report;
    $out = (new CssSanitizer)->sanitize($source, 'botanical', 'theme.css', $report, [], [], FACT_KINDS, $options);

    return [$out, $report->errorCodes(), $report];
}

it('lets a package style its own elements under a published fact', function (string $selector, string $scoped) {
    [$out, $codes] = factcss($selector . '{color:#222}');
    expect($codes)->toBe([])->and($out)->toBe($scoped . '{color:#222}');
})->with([
    'option' => ['[data-inv-opt-cover_layout="hero"] .pkg-deco', '[data-inv-theme="botanical"][data-inv-opt-cover_layout="hero"] .pkg-deco'],
    'option, single quotes' => ["[data-inv-opt-tone='soft'] .pkg-a", '[data-inv-theme="botanical"][data-inv-opt-tone="soft"] .pkg-a'],
    'option, bare value' => ['[data-inv-opt-tone=bold] .pkg-a', '[data-inv-theme="botanical"][data-inv-opt-tone="bold"] .pkg-a'],
    'name length' => ['[data-inv-name-len="long"] .pkg-a', '[data-inv-theme="botanical"] [data-inv-name-len="long"] .pkg-a'],
    'guest length' => ['[data-inv-guest-len="short"] .pkg-a > .pkg-b', '[data-inv-theme="botanical"] [data-inv-guest-len="short"] .pkg-a>.pkg-b'],
    'has photo' => ['[data-inv-has-photo="false"] .pkg-a', '[data-inv-theme="botanical"] [data-inv-has-photo="false"] .pkg-a'],
    'two facts' => ['[data-inv-has-photo="true"][data-inv-name-len="mid"] .pkg-a', '[data-inv-theme="botanical"] [data-inv-has-photo="true"][data-inv-name-len="mid"] .pkg-a'],
    'option on .inv-root' => ['.inv-root[data-inv-opt-tone="soft"] .pkg-a', '.inv-root[data-inv-opt-tone="soft"][data-inv-theme="botanical"] .pkg-a'],
    'option on body' => ['body[data-inv-opt-tone="soft"] .pkg-a', '[data-inv-theme="botanical"][data-inv-opt-tone="soft"] .pkg-a'],
    'attribute name is case-insensitive, output is normalized' => ['[DATA-INV-OPT-Tone="soft"] .pkg-a', '[data-inv-theme="botanical"][data-inv-opt-tone="soft"] .pkg-a'],
    'cover wrapper with its own hook' => ['[data-inv-section="cover"][data-inv-name-len="mid"] .pkg-a', '[data-inv-theme="botanical"] [data-inv-section="cover"][data-inv-name-len="mid"] .pkg-a'],
    'option then cover fact' => ['[data-inv-opt-tone="soft"] [data-inv-guest-len="long"] .pkg-a', '[data-inv-theme="botanical"][data-inv-opt-tone="soft"] [data-inv-guest-len="long"] .pkg-a'],
    'package id' => ['[data-inv-name-len="short"] #pkg-x', '[data-inv-theme="botanical"] [data-inv-name-len="short"] #pkg-x'],
    'with a pseudo-element' => ['[data-inv-has-photo="true"] .pkg-a::after', '[data-inv-theme="botanical"] [data-inv-has-photo="true"] .pkg-a::after'],
    'past a system block as a sibling condition' => ['[data-inv-name-len="long"] .inv-q--names ~ .pkg-a', '[data-inv-theme="botanical"] [data-inv-name-len="long"] .inv-q--names~.pkg-a'],
]);

it('is idempotent: the scoped output is accepted again as it is', function () {
    [$once] = factcss('[data-inv-opt-tone="soft"] .pkg-a{color:#222}');
    [$twice, $codes] = factcss($once);
    expect($codes)->toBe([])->and($twice)->toBe($once);
});

it('may hide or restyle a decoration of the package under a fact (the point of the feature)', function () {
    [$out, $codes] = factcss('[data-inv-has-photo="false"] .pkg-arch{display:none}');
    expect($codes)->toBe([])->and($out)->toContain('display:none');
});

it('refuses every other use of the fact namespace', function (string $selector) {
    [$out, $codes] = factcss($selector . '{color:#222}');
    expect($out)->toBeNull()->and($codes)->toBe(['css_selector_forbidden']);
})->with([
    // operators and shapes beyond exact match
    'prefix operator' => ['[data-inv-opt-tone^="s"] .pkg-a'],
    'suffix operator' => ['[data-inv-opt-tone$="t"] .pkg-a'],
    'substring operator' => ['[data-inv-opt-tone*="o"] .pkg-a'],
    'word operator' => ['[data-inv-opt-tone~="soft"] .pkg-a'],
    'dash operator' => ['[data-inv-opt-tone|="soft"] .pkg-a'],
    'presence only' => ['[data-inv-opt-tone] .pkg-a'],
    'case flag' => ['[data-inv-name-len="long" i] .pkg-a'],
    'empty value' => ['[data-inv-name-len=""] .pkg-a'],
    // values outside the closed lists
    'unknown value of a fixed fact' => ['[data-inv-name-len="huge"] .pkg-a'],
    'value of another fact' => ['[data-inv-has-photo="long"] .pkg-a'],
    'unknown value of an option' => ['[data-inv-opt-tone="loud"] .pkg-a'],
    'case differs' => ['[data-inv-name-len="LONG"] .pkg-a'],
    'value with a space' => ['[data-inv-opt-tone="so ft"] .pkg-a'],
    // names outside the namespace the engine publishes
    'upper-case name, unknown option' => ['[DATA-INV-OPT-ghost="x"] .pkg-a'],
    'upper-case name, bad value' => ['[Data-Inv-Name-Len="huge"] .pkg-a'],
    'upper-case name, as the subject' => ['[DATA-INV-HAS-PHOTO="true"]'],
    'option not in variants' => ['[data-inv-opt-ghost="x"] .pkg-a'],
    'empty option id' => ['[data-inv-opt-="soft"] .pkg-a'],
    'unknown name-*' => ['[data-inv-name-length="long"] .pkg-a'],
    'unknown guest-*' => ['[data-inv-guest-name="x"] .pkg-a'],
    'unknown has-*' => ['[data-inv-has-music="true"] .pkg-a'],
    // not a root condition
    'as the subject' => ['[data-inv-name-len="long"]'],
    'as the subject with a class' => ['.pkg-a[data-inv-name-len="long"]'],
    'on a descendant' => ['.pkg-a [data-inv-name-len="long"] .pkg-b'],
    'inside :not' => ['.pkg-a:not([data-inv-name-len="long"])'],
    'inside :is' => ['.pkg-a:is([data-inv-name-len="long"])'],
    'inside :where' => ['.pkg-a:where([data-inv-has-photo="true"])'],
    'on a generic tag' => ['div[data-inv-name-len="long"] .pkg-a'],
    'on the universal selector' => ['*[data-inv-name-len="long"] .pkg-a'],
    'cover fact on the root element' => ['.inv-root[data-inv-name-len="long"] .pkg-a'],
    'cover fact on html' => ['html[data-inv-has-photo="true"] .pkg-a'],
    'option on a cover wrapper' => ['[data-inv-section="cover"][data-inv-opt-tone="soft"] .pkg-a'],
    'option and cover fact in one compound' => ['[data-inv-opt-tone="soft"][data-inv-name-len="long"] .pkg-a'],
    'cover fact in the third compound' => ['[data-inv-opt-tone="soft"] .pkg-b [data-inv-name-len="long"] .pkg-a'],
    'cover fact after a package element' => ['.pkg-b [data-inv-name-len="long"] .pkg-a'],
    'option in the second compound' => ['[data-inv-name-len="long"] [data-inv-opt-tone="soft"] .pkg-a'],
    'cover wrapper hook of another section' => ['[data-inv-section="rsvp"][data-inv-name-len="long"] .pkg-a'],
    'with a package class in the root compound' => ['.pkg-a[data-inv-name-len="long"] .pkg-b'],
    'with a pseudo-class in the root compound' => ['[data-inv-name-len="long"]:hover .pkg-a'],
    'with a state in the root compound' => ['[data-inv-name-len="long"]:first-child .pkg-a'],
    'with an id in the root compound' => ['#pkg-x[data-inv-name-len="long"] .pkg-a'],
    // the has() family
    ':has with a fact' => ['.pkg-a:has([data-inv-name-len="long"])'],
    'has on the root' => [':has(.pkg-a) .pkg-b'],
    // the subject after the fact must be a package element
    'subject is a system block' => ['[data-inv-name-len="long"] .inv-q--rsvp'],
    'subject is the names block' => ['[data-inv-name-len="long"] .inv-q--names'],
    'subject is a hook of the section' => ['[data-inv-has-photo="true"] [data-inv-section="rsvp"]'],
    'subject is a data-q hook' => ['[data-inv-has-photo="true"] [data-q="rsvp"]'],
    'subject is the photo' => ['[data-inv-has-photo="false"] .inv-q--cover-photo'],
    'subject is a photo part' => ['[data-inv-has-photo="false"] .inv-q-photo__img'],
    'subject is the root' => ['[data-inv-has-photo="false"] .inv-root'],
    'subject is a bare tag' => ['[data-inv-has-photo="false"] div'],
    'subject is the universal selector' => ['[data-inv-has-photo="false"] *'],
    'subject is an :is() mixing a system block' => ['[data-inv-has-photo="false"] :is(.pkg-a, .inv-q--rsvp)'],
    'subject is a class of a system block by attribute' => ['[data-inv-has-photo="false"] [class*="inv-q"]'],
    'sibling of the root' => ['[data-inv-has-photo="false"] + .pkg-a ~ .inv-q--date'],
]);

it('stops what the strict parser or the escape ban already refuse before the fact grammar', function (string $selector) {
    [$out, $codes] = factcss($selector . '{color:#222}');
    expect($out)->toBeNull()->and($codes)->not->toBe([]);
})->with([
    'injection in the value' => ['[data-inv-opt-tone="soft\\"] .pkg-a, body{display:none}/*"] .pkg-a'],
    'angle brackets' => ['[data-inv-opt-tone="<b>"] .pkg-a'],
    'escape in the value' => ['[data-inv-opt-tone="so\\66t"] .pkg-a'],
    'escape in the name' => ['[data-inv-opt-t\\6fne="soft"] .pkg-a'],
]);

it('cannot hide, move or cover a system block under a fact, with any of the allowed shapes', function (string $selector, string $decl) {
    [$out, $codes] = factcss("{$selector}{{$decl}}");
    expect($out)->toBeNull()->and($codes)->not->toBe([]);
})->with([
    'system block, display none' => ['[data-inv-name-len="long"] .inv-q--rsvp', 'display:none'],
    'system block, offset' => ['[data-inv-name-len="long"] .inv-q--names', 'transform:translateY(-999px)'],
    'system block, opacity' => ['[data-inv-opt-tone="soft"] .inv-q--cta', 'opacity:0'],
    'wrapper of a block, display none' => ['[data-inv-has-photo="false"] .pkg-w', 'display:none'],
    'wrapper of a block, z-index' => ['[data-inv-has-photo="false"] .pkg-w', 'z-index:9'],
    'wrapper of a block, clip' => ['[data-inv-has-photo="true"] .pkg-w', 'clip-path:inset(100%)'],
    'wrapper of a block, overflow' => ['[data-inv-has-photo="true"] .pkg-w', 'overflow:hidden'],
    'wrapper of a block, size zero' => ['[data-inv-has-photo="true"] .pkg-w', 'height:0'],
    'class of the block, visibility' => ['[data-inv-has-photo="true"] .pkg-rsvp', 'visibility:hidden'],
    'descendant combinator through the wrapper' => ['[data-inv-has-photo="true"] .pkg-w .inv-q--rsvp', 'display:none'],
    'block through an :is() beside a package class' => ['[data-inv-has-photo="true"] .pkg-a:is(.inv-q--rsvp)', 'display:none'],
    'block combined with a hook class' => ['[data-inv-has-photo="true"] .pkg-rsvp.inv-q--rsvp', 'display:none'],
]);

it('keeps the visual restrictions of the rule on a package element that wraps blocks, under any fact', function () {
    [$out, $codes] = factcss('[data-inv-name-len="long"] .pkg-w{padding:1rem;background:#fff;border-radius:1rem}');
    expect($codes)->toBe([])->and($out)->not->toBeNull();
});

it('does not let a fact open a rule that the sanitizer refuses without it', function (string $decl) {
    [$out, $codes] = factcss("[data-inv-name-len=\"long\"] .pkg-a{{$decl}}");
    expect($out)->toBeNull()->and($codes)->not->toBe([]);
})->with([
    'position fixed' => ['position:fixed'],
    'important' => ['color:red !important'],
    'url to another origin' => ['background:url(https://evil.example/x.png)'],
    'z-index above the blocks' => ['z-index:99'],
]);

it('works the same inside @media', function () {
    [$out, $codes] = factcss('@media (min-width: 600px){[data-inv-name-len="long"] .pkg-a{font-size:2rem}[data-inv-name-len="long"] .inv-q--rsvp{display:none}}');
    expect($out)->toBeNull()->and($codes)->toBe(['css_selector_forbidden']);
    [$ok, $codes] = factcss('@media (min-width: 600px){[data-inv-name-len="long"] .pkg-a{font-size:2rem}}');
    expect($codes)->toBe([])->and($ok)->toContain('[data-inv-name-len="long"]');
});

it('without declared options no data-inv-opt-* selector passes', function () {
    [$out, $codes] = factcss('[data-inv-opt-tone="soft"] .pkg-a{color:#222}', []);
    expect($out)->toBeNull()->and($codes)->toBe(['css_selector_forbidden']);
});

it('keeps the existing hooks working (stage, parity, section) and the other attributes as they were', function () {
    [$out, $codes] = factcss('[data-inv-stage="save_the_date"] .pkg-a{color:#222}');
    expect($codes)->toBe([])->and($out)->not->toBeNull();
    [$out, $codes] = factcss('input[name=x]{color:#222}');
    expect($out)->toBeNull()->and($codes)->toBe(['css_selector_forbidden']);
});

// Through the whole validator: the options come from the package's own manifest ---------------------------------------

it('reads the closed list of an option from the manifest variants of the package being validated', function () {
    $variants = fn (array $m) => $m + ['variants' => ['cover_layout' => ['values' => ['arch', 'hero'], 'default' => 'arch']]];
    $css = file_get_contents(__DIR__ . '/../../Fixtures/TemplatePackage/good/theme.css');

    $ok = PackageBuilder::good()->json('manifest.json', $variants)->with('theme.css', $css . "\n[data-inv-opt-cover_layout=\"hero\"] .pkg-hero{padding-top:3rem}\n")->validate();
    expect(PackageBuilder::errors($ok))->toBe([])->and($ok['artifacts']['css'])->toContain('[data-inv-theme="botanical"][data-inv-opt-cover_layout="hero"] .pkg-hero');

    $bad = PackageBuilder::good()->json('manifest.json', $variants)->with('theme.css', $css . "\n[data-inv-opt-cover_layout=\"split\"] .pkg-hero{padding-top:3rem}\n")->validate();
    expect(PackageBuilder::errors($bad))->toBe(['css_selector_forbidden'])->and($bad['artifacts']['css'])->toBeNull();

    $none = PackageBuilder::good()->with('theme.css', $css . "\n[data-inv-opt-cover_layout=\"hero\"] .pkg-hero{padding-top:3rem}\n")->validate();
    expect(PackageBuilder::errors($none))->toBe(['css_selector_forbidden']);
});

it('allows on/off for a toggle bound to an option, and nothing else', function () {
    $css = file_get_contents(__DIR__ . '/../../Fixtures/TemplatePackage/good/theme.css');
    $with = fn (string $rule) => PackageBuilder::good()->json('editable.json', function (array $e) {
        $e['fields'][] = ['id' => 'show_ornament', 'type' => 'toggle', 'group' => 'cover', 'label' => ['es' => 'Adorno', 'en' => 'Ornament'], 'bind' => ['option' => 'show_ornament'], 'plan' => 'free'];

        return $e;
    })->with('theme.css', $css . "\n{$rule}\n")->validate();

    $ok = $with('[data-inv-opt-show_ornament="off"] .pkg-hero{padding-top:3rem}');
    expect(PackageBuilder::errors($ok))->not->toContain('css_selector_forbidden')->and($ok['artifacts']['css'])->toContain('[data-inv-opt-show_ornament="off"]');
    expect(PackageBuilder::errors($with('[data-inv-opt-show_ornament="maybe"] .pkg-hero{padding-top:3rem}')))->toContain('css_selector_forbidden');
});

it('ignores a malformed variant when building the closed list', function () {
    $css = file_get_contents(__DIR__ . '/../../Fixtures/TemplatePackage/good/theme.css');
    $r = PackageBuilder::good()
        ->json('manifest.json', fn (array $m) => $m + ['variants' => ['tone' => ['values' => ['soft', 7, 'BAD VALUE'], 'default' => 'soft'], 'junk' => 'x', 3 => ['values' => ['a']]]])
        ->with('theme.css', $css . "\n[data-inv-opt-tone=\"soft\"] .pkg-hero{padding-top:3rem}\n[data-inv-opt-junk=\"x\"] .pkg-hero{padding-top:3rem}\n")->validate();
    expect(PackageBuilder::errors($r))->toContain('variant_unknown')->and(PackageBuilder::errors($r))->toContain('css_selector_forbidden');
});

it('cannot be declared in the HTML of a package: the engine alone publishes the facts', function (string $attr) {
    $html = '<div class="pkg-w" ' . $attr . '><q-rsvp></q-rsvp></div>';
    $r = PackageBuilder::good()->with('sections/rsvp.html', $html)->validate();
    expect(PackageBuilder::errors($r))->toContain('behavior_unknown')->and($r['artifacts']['ast'] ?? [])->not->toHaveKey('sections/rsvp.html');
})->with([
    'option' => ['data-inv-opt-tone="soft"'],
    'name length' => ['data-inv-name-len="long"'],
    'guest length' => ['data-inv-guest-len="short"'],
    'has photo' => ['data-inv-has-photo="false"'],
    'on a block' => ['data-inv-has-photo="true" data-q="rsvp"'],
]);

it('cannot be declared on a q-* block or on the root of a layout either', function () {
    $r = PackageBuilder::good()->with('sections/closing.html', '<q-closing data-inv-has-photo="true"></q-closing>')->validate();
    expect(PackageBuilder::errors($r))->not->toBe([]);
});
