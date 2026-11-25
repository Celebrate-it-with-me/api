<?php

use App\Support\TemplatePackage\BlockCatalog;
use App\Support\TemplatePackage\CssSanitizer;
use App\Support\TemplatePackage\Report;
use Tests\Unit\TemplatePackage\PackageBuilder;

/**
 * Image zones: a template places the organizer's picture in `locations` or `closing` with `<q-section-image>` and declares
 * the field in `editable.json`. These tests cover the validator side (fields, block, scope, cross-checks), the restricted
 * CSS class of the picture (`zone`) and the fact `data-inv-has-image`.
 */
afterEach(fn () => PackageBuilder::cleanup());

const ZONE_KINDS = ['pkg-pic' => 'zone', 'pkg-box' => 'wrapper', 'pkg-rsvp' => 'system'];

function zoneField(string $section = 'locations', array $extra = []): array
{
    return [
        'id' => "{$section}_image", 'type' => 'image', 'group' => 'cover',
        'label' => ['es' => 'Foto', 'en' => 'Photo'],
        'bind' => ['section' => $section, 'setting' => 'image', 'focus' => 'focus'],
        'aspect' => '16:10', 'minWidth' => 960, 'plan' => 'full',
    ] + $extra;
}

/** The good package with the closing (and optionally locations) section drawing the zone, and its fields declared. */
function zonePackage(array $sections = ['closing'], bool $fields = true, bool $blocks = true): PackageBuilder
{
    $b = PackageBuilder::good();
    foreach ($sections as $type) {
        if ($blocks) {
            $b->with("sections/{$type}.html", '<div class="pkg-box"><q-section-image class="pkg-pic" aspect="16:10" fit="cover"></q-section-image><h2 class="pkg-hero__title">{{ section.title }}</h2></div>');
        } elseif ($type !== 'closing') {
            $b->with("sections/{$type}.html", '<div class="pkg-box"><h2 class="pkg-hero__title">{{ section.title }}</h2></div>');
        }
    }
    if ($fields) {
        $b->json('editable.json', function (array $e) use ($sections) {
            foreach ($sections as $type) {
                $e['fields'][] = zoneField($type, ['alt' => ['es' => 'Vista del lugar', 'en' => 'View of the venue']]);
            }

            return $e;
        });
    }

    return $b;
}

/** @return array{0: ?string, 1: list<string>} */
function zonecss(string $source): array
{
    $report = new Report;
    $out = (new CssSanitizer)->sanitize($source, 'botanical', 'theme.css', $report, [], [], ZONE_KINDS, []);

    return [$out, $report->errorCodes()];
}

it('declares the block with its closed props and a scope of two sections', function () {
    expect(BlockCatalog::BLOCKS)->toHaveKey('section-image')
        ->and(BlockCatalog::BLOCKS['section-image']['scope'])->toBe('section:locations|closing')
        ->and(BlockCatalog::BLOCKS['section-image']['max'])->toBe(1)
        ->and(BlockCatalog::IMAGE_ZONE_SECTIONS)->toBe(['cover', 'locations', 'closing']);
});

it('accepts a package with a zone in the closing and in locations, with the field and the block', function () {
    $result = zonePackage(['locations', 'closing'])->validate();

    expect($result['report']['errors'])->toBe([])
        ->and(PackageBuilder::warnings($result))->not->toContain('editable_zone_without_block')
        ->and(PackageBuilder::warnings($result))->not->toContain('zone_image_without_field')
        ->and($result['artifacts']['json']['editable']['fields'][2]['alt'])->toBe(['es' => 'Vista del lugar', 'en' => 'View of the venue'])
        ->and(collect($result['artifacts']['ast']['sections/closing.html'])->toJson())->toContain('section-image');
});

it('accepts an empty alt (decorative) and keeps it', function () {
    $b = zonePackage(['closing'], false);
    $b->json('editable.json', function (array $e) {
        $e['fields'][] = zoneField('closing', ['alt' => ['es' => '', 'en' => '']]);

        return $e;
    });
    $result = $b->validate();

    expect($result['report']['errors'])->toBe([])
        ->and($result['artifacts']['json']['editable']['fields'][2]['alt'])->toBe(['es' => '', 'en' => '']);
});

it('rejects an image field on any other section', function (string $section) {
    $b = PackageBuilder::good()->json('editable.json', function (array $e) use ($section) {
        $e['fields'][] = zoneField($section);

        return $e;
    });

    expect(PackageBuilder::errors($b->validate()))->toContain('editable_bind_unknown');
})->with(['dress_code', 'rsvp', 'music', 'gallery', 'itinerary', 'padrinos', 'countdown']);

it('rejects two image fields for the same section', function () {
    $b = zonePackage(['closing'], false)->json('editable.json', function (array $e) {
        $e['fields'][] = zoneField('closing');
        $e['fields'][] = ['id' => 'closing_image2'] + zoneField('closing');

        return $e;
    });

    expect(PackageBuilder::errors($b->validate()))->toContain('editable_image_duplicate');
});

it('refuses a malformed alt: tags, too long, one language, text field, bidi control', function (mixed $alt, string $type) {
    $b = zonePackage(['closing'], false)->json('editable.json', function (array $e) use ($alt, $type) {
        $field = zoneField('closing', ['alt' => $alt]);
        if ($type === 'text') {
            $field = ['id' => 'closing_txt', 'type' => 'text', 'label' => ['es' => 'T', 'en' => 'T'], 'bind' => ['section' => 'closing', 'field' => 'title'], 'alt' => $alt];
        }
        $e['fields'][] = $field;

        return $e;
    });

    expect(PackageBuilder::errors($b->validate()))->toContain('editable_invalid');
})->with([
    'tags' => [['es' => '<b>x</b>', 'en' => 'x'], 'image'],
    'too long' => [['es' => str_repeat('a', 161), 'en' => 'x'], 'image'],
    'one language' => [['es' => 'x'], 'image'],
    'not a pair' => ['texto', 'image'],
    'on a text field' => [['es' => 'x', 'en' => 'x'], 'text'],
]);

it('refuses a bidirectional control in the alt (the whole JSON is rejected)', function () {
    $b = zonePackage(['closing'], false);
    $b->files['editable.json'] = str_replace('"fields": [', '"fields": [ { "id": "z", "type": "image", "label": { "es": "F", "en": "P" }, "bind": { "section": "closing", "setting": "image" }, "alt": { "es": "a' . "\u{202E}" . 'b", "en": "x" } },', $b->files['editable.json']);

    expect(PackageBuilder::errors($b->validate()))->toContain('editable_invalid');
});

it('refuses the block outside locations and closing', function (string $type) {
    $b = PackageBuilder::good()->with("sections/{$type}.html", '<div class="pkg-x"><q-section-image></q-section-image></div>');
    $codes = PackageBuilder::errors($b->validate());

    expect($codes)->toContain('block_scope');
})->with(['rsvp', 'cover']);

it('refuses two zone blocks in one file and children inside the block', function () {
    $two = PackageBuilder::good()->with('sections/closing.html', '<div><q-section-image></q-section-image><q-section-image></q-section-image></div>');
    $kids = PackageBuilder::good()->with('sections/closing.html', '<div><q-section-image><img src="assets/img/wash.svg" alt=""></q-section-image></div>');
    $badProp = PackageBuilder::good()->with('sections/closing.html', '<div><q-section-image aspect="9:16"></q-section-image></div>');
    $layout = PackageBuilder::good()->with('layout.html', str_replace('<q-sections></q-sections>', '<q-sections></q-sections><q-section-image></q-section-image>', $layout = PackageBuilder::good()->files['layout.html']));

    expect(PackageBuilder::errors($two->validate()))->toContain('block_too_many')
        ->and(PackageBuilder::errors($kids->validate()))->toContain('block_children')
        ->and(PackageBuilder::errors($badProp->validate()))->toContain('block_prop_invalid')
        ->and(PackageBuilder::errors($layout->validate()))->toContain('block_scope');
});

it('warns about a field without the block and a block without the field, never an error', function () {
    $noBlock = zonePackage(['locations'], true, false)->validate();
    $noField = zonePackage(['closing'], false)->validate();

    expect($noBlock['report']['errors'])->toBe([])
        ->and(PackageBuilder::warnings($noBlock))->toContain('editable_zone_without_block')
        ->and($noField['report']['errors'])->toBe([])
        ->and(PackageBuilder::warnings($noField))->toContain('zone_image_without_field');
});

it('lets the cover keep its own image field untouched', function () {
    $result = PackageBuilder::good()->validate();

    expect($result['report']['errors'])->toBe([])
        ->and(PackageBuilder::warnings($result))->not->toContain('zone_image_without_field');
});

// ---------------------------------------------------------------- CSS: the `zone` class

it('lets the zone picture be dressed with the allowed properties', function (string $css) {
    [$out, $codes] = zonecss($css);
    expect($codes)->toBe([])->and($out)->not->toBeNull();
})->with([
    'class on the block' => ['.pkg-pic{border-radius:24px;box-shadow:0 8px 24px rgba(0,0,0,.2);border:2px solid #c9a24a;aspect-ratio:16/10;object-fit:cover;object-position:50% 30%}'],
    'the system hook' => ['.inv-q--section-image{border-radius:9999px 9999px 0 0;max-width:640px;width:100%}'],
    'a soft filter' => ['.pkg-pic{filter:blur(4px) brightness(.8)}'],
    'a gradient mask' => ['.pkg-pic{mask-image:linear-gradient(#000 70%,transparent);mask-size:100% 100%}'],
    'a simple clip' => ['.pkg-pic{clip-path:ellipse(50% 50% at 50% 50%)}'],
    'opacity above .6' => ['.pkg-pic{opacity:.8}'],
    'a minimum height' => ['.pkg-pic{min-height:40svh}'],
    'a colour transition' => ['.pkg-pic{transition:box-shadow .2s ease-out}'],
    'a hover repaint' => ['.pkg-pic:hover{box-shadow:0 4px 12px rgba(0,0,0,.2)}'],
]);

it('refuses what could move, cover or hide the picture over a control', function (string $css) {
    [$out, $codes] = zonecss($css);
    expect($out)->toBeNull()->and($codes)->not->toBe([]);
})->with([
    'position' => ['.pkg-pic{position:absolute}'],
    'relative position' => ['.pkg-pic{position:relative}'],
    'top' => ['.pkg-pic{top:0}'],
    'inset' => ['.pkg-pic{inset:0}'],
    'z-index' => ['.pkg-pic{z-index:99}'],
    'transform' => ['.pkg-pic{transform:translateY(-400px)}'],
    'translate' => ['.pkg-pic{translate:0 -400px}'],
    'scale' => ['.pkg-pic{scale:5}'],
    'rotate' => ['.pkg-pic{rotate:90deg}'],
    'negative margin' => ['.pkg-pic{margin-top:-9999px}'],
    'margin with calc' => ['.pkg-pic{margin-top:calc(-100vh)}'],
    'pointer-events' => ['.pkg-pic{pointer-events:none}'],
    'mix-blend-mode' => ['.pkg-pic{mix-blend-mode:difference}'],
    'display none' => ['.pkg-pic{display:none}'],
    'display grid' => ['.pkg-pic{display:grid}'],
    'visibility' => ['.pkg-pic{visibility:hidden}'],
    'overflow' => ['.pkg-pic{overflow:hidden}'],
    'content' => ['.pkg-pic::after{content:"x"}'],
    'animation' => ['.pkg-pic{animation:x 4s}'],
    'height' => ['.pkg-pic{height:100vh}'],
    'opacity too low' => ['.pkg-pic{opacity:.2}'],
    'huge shadow' => ['.pkg-pic{box-shadow:0 0 400px #000}'],
    'huge outline' => ['.pkg-pic{outline:300px solid #000}'],
    'wide' => ['.pkg-pic{width:5000px}'],
    'wider than the screen' => ['.pkg-pic{width:300vw}'],
    'wide by calc' => ['.pkg-pic{width:calc(100% + 400px)}'],
    'max-width var' => ['.pkg-pic{max-width:var(--pkg-w)}'],
    'tall' => ['.pkg-pic{min-height:200svh}'],
    'a pixel' => ['.pkg-pic{width:2px}'],
    'blur too big' => ['.pkg-pic{filter:blur(40px)}'],
    'brightness near zero' => ['.pkg-pic{filter:brightness(.1)}'],
    'filter url' => ['.pkg-pic{filter:url(#x)}'],
    'hook position' => ['.inv-q--section-image{position:fixed}'],
    'hook transform' => ['.inv-q--section-image{transform:scale(10)}'],
    'state moves it' => ['.pkg-pic:hover{transform:scale(2)}'],
    'zone as a descendant selector target' => ['.pkg-box .pkg-pic{z-index:5}'],
    'under a hover of the parent' => ['.pkg-box:hover .pkg-pic{position:absolute}'],
    'background with an external url' => ['.pkg-pic{background-image:url(https://evil.example/x.png)}'],
]);

it('keeps the wrapper of the zone under the container rules', function (string $css) {
    [$out, $codes] = zonecss($css);
    expect($out)->toBeNull()->and($codes)->not->toBe([]);
})->with([
    'overflow' => ['.pkg-box{overflow:hidden}'],
    'z-index' => ['.pkg-box{z-index:3}'],
    'filter' => ['.pkg-box{filter:blur(2px)}'],
    'mask' => ['.pkg-box{mask-image:linear-gradient(#000,transparent)}'],
    'transform' => ['.pkg-box{transform:scale(.5)}'],
    'tiny' => ['.pkg-box{max-height:10px}'],
]);

it('refuses a class used on the zone and on a system block to loosen the system block', function () {
    // pkg-pic is "zone"; the same class on a system block must not become more permissive: the stricter kind wins.
    $b = PackageBuilder::good()->with('sections/closing.html', '<div class="pkg-box"><q-section-image class="pkg-shared"></q-section-image><q-closing class="pkg-shared"></q-closing></div>');
    $b->files['theme.css'] .= "\n.pkg-shared{border-radius:8px}\n";
    $ok = $b->validate();
    $b->files['theme.css'] .= "\n.pkg-shared{filter:blur(2px)}\n";
    $bad = $b->validate();

    expect(PackageBuilder::errors($ok))->toBe([])
        ->and(PackageBuilder::errors($bad))->not->toBe([]);
});

// ---------------------------------------------------------------- facts: has-image (spike A7)

// Spike A7 (2026-11-25): `[data-inv-has-photo]` bare works anywhere, but tied to a section it only accepted the cover;
// the zones got their own fact `data-inv-has-image`, which also accepts `locations` and `closing` as its section hook.

it('lets a package read data-inv-has-image on the wrapper of an image zone', function (string $selector, string $scoped) {
    [$out, $codes] = zonecss($selector . '{color:#222}');
    expect($codes)->toBe([])->and($out)->toBe($scoped . '{color:#222}');
})->with([
    'bare' => ['[data-inv-has-image="false"] .pkg-a', '[data-inv-theme="botanical"] [data-inv-has-image="false"] .pkg-a'],
    'with locations' => ['[data-inv-section="locations"][data-inv-has-image="true"] .pkg-a', '[data-inv-theme="botanical"] [data-inv-section="locations"][data-inv-has-image="true"] .pkg-a'],
    'with closing' => ['[data-inv-section="closing"][data-inv-has-image="false"] .pkg-a > .pkg-b', '[data-inv-theme="botanical"] [data-inv-section="closing"][data-inv-has-image="false"] .pkg-a>.pkg-b'],
    'with cover, has-photo as before' => ['[data-inv-section="cover"][data-inv-has-photo="true"] .pkg-a', '[data-inv-theme="botanical"] [data-inv-section="cover"][data-inv-has-photo="true"] .pkg-a'],
]);

it('refuses every other use of has-image', function (string $selector) {
    [$out, $codes] = zonecss($selector . '{color:#222}');
    expect($out)->toBeNull()->and($codes)->toBe(['css_selector_forbidden']);
})->with([
    'as the subject' => ['[data-inv-has-image="true"]'],
    'prefix operator' => ['[data-inv-has-image^="t"] .pkg-a'],
    'presence only' => ['[data-inv-has-image] .pkg-a'],
    'a value outside the list' => ['[data-inv-has-image="maybe"] .pkg-a'],
    'inside :not' => ['.pkg-a:not([data-inv-has-image="true"])'],
    'with another section' => ['[data-inv-section="rsvp"][data-inv-has-image="true"] .pkg-a'],
    'with a class' => ['.pkg-b[data-inv-has-image="true"] .pkg-a'],
    'on the zone picture' => ['[data-inv-has-image="true"] .inv-q--section-image'],
    'a name next to it' => ['[data-inv-has-imagex="true"] .pkg-a'],
]);
