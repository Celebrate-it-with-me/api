<?php

use App\Support\TemplatePackage\CssSanitizer;
use App\Support\TemplatePackage\HtmlAstBuilder;
use App\Support\TemplatePackage\Report;
use Tests\Unit\TemplatePackage\PackageBuilder;

/**
 * Wave 7 (guard hardening, server side): one rejected and one allowed case per rule of the binding contract
 * (docs/plans/wave7-guard-hardening.md), reproducing the N-1..N-7 findings of the final wave 6 audit.
 */
afterEach(fn () => PackageBuilder::cleanup());

const W7_KINDS = ['pkg-w' => 'wrapper', 'pkg-rsvp' => 'system'];

/** @return array{0: ?string, 1: list<string>, 2: Report} */
function w7css(string $source, array $kinds = W7_KINDS): array
{
    $report = new Report;
    $out = (new CssSanitizer)->sanitize($source, 'botanical', 'theme.css', $report, [], [], $kinds);

    return [$out, $report->errorCodes(), $report];
}

// Rule 1: containers of blocks (N-1, N-3) -----------------------------------------------------------------------------

it('refuses stacking, clipping and overlapping properties on the container of a block', function (string $selector, string $decl) {
    [$out, $codes] = w7css("{$selector}{{$decl}}");
    expect($out)->toBeNull()->and($codes)->toBe(['block_container_property']);
})->with([
    'wrapper z-index (N-1)' => ['.pkg-w', 'position:relative;z-index:1'],
    'wrapper isolation' => ['.pkg-w', 'isolation:isolate'],
    'wrapper opacity' => ['.pkg-w', 'opacity:.9'],
    'wrapper overflow' => ['.pkg-w', 'overflow:hidden'],
    'wrapper overflow-x' => ['.pkg-w', 'overflow-x:clip'],
    'wrapper clip-path' => ['.pkg-w', 'clip-path:inset(0)'],
    'wrapper mask' => ['.pkg-w', 'mask-image:linear-gradient(#000,#000)'],
    'wrapper filter' => ['.pkg-w', 'filter:blur(1px)'],
    'wrapper backdrop-filter' => ['.pkg-w', 'backdrop-filter:blur(2px)'],
    'wrapper mix-blend-mode' => ['.pkg-w', 'mix-blend-mode:multiply'],
    'wrapper grid-area (N-3)' => ['.pkg-w', 'grid-area:1/1'],
    'wrapper grid-row' => ['.pkg-w', 'grid-row:1'],
    'wrapper grid-column' => ['.pkg-w', 'grid-column:1/3'],
    'wrapper order' => ['.pkg-w', 'order:-1'],
    'wrapper float' => ['.pkg-w', 'float:left'],
    'wrapper contain' => ['.pkg-w', 'contain:size'],
    'wrapper container-type' => ['.pkg-w', 'container-type:size'],
    'wrapper absolute' => ['.pkg-w', 'position:absolute'],
    'section hook z-index' => ['[data-inv-section="rsvp"]', 'z-index:2'],
    'block hook overflow' => ['.inv-q--rsvp', 'overflow:hidden'],
    'data-q hook opacity' => ['[data-q="rsvp"]', 'opacity:.5'],
    'block class isolation' => ['.pkg-rsvp', 'isolation:isolate'],
    'generic selector z-index' => ['div', 'z-index:1'],
    'generic child z-index' => ['.pkg-w > *', 'z-index:1'],
]);

it('allows the visual and flow properties on the container of a block, and the same properties elsewhere', function (string $selector, string $decl) {
    [$out, $codes] = w7css("{$selector}{{$decl}}");
    expect($codes)->toBe([])->and($out)->not->toBeNull();
})->with([
    'wrapper layout' => ['.pkg-w', 'display:flex;gap:1rem;padding:2rem 1rem;background-color:#fff;position:relative;opacity:1'],
    'wrapper static' => ['.pkg-w', 'position:static'],
    'block hook visual' => ['.inv-q--rsvp', 'background-color:#fff;border-radius:8px'],
    'free element stacking' => ['.pkg-deco', 'position:absolute;z-index:2;overflow:hidden;opacity:.5;isolation:isolate'],
    'generic selector without z-index' => ['p', 'position:relative'],
    'decoration of a wrapper' => ['.pkg-w::before', 'position:absolute;z-index:-1;overflow:hidden'],
]);

// Rule 2: size floor (N-2) ---------------------------------------------------------------------------------------------

it('refuses containers fixed under 48px', function (string $decl) {
    [$out, $codes] = w7css(".pkg-w{{$decl}}");
    expect($out)->toBeNull()->and($codes)->toBe(['block_container_too_small']);
})->with([
    'width 8px (N-2)' => 'width:8px',
    'height 47px' => 'height:47px',
    'max-width 40px' => 'max-width:40px',
    'max-height 2rem' => 'max-height:2rem',
    'inline-size' => 'inline-size:10px',
    'min() of small' => 'width:min(30px,60px)',
    'clamp small' => 'height:clamp(10px,20px,30px)',
    'vw' => 'width:5vw',
]);

it('allows containers of 48px or more, fluid or unsized', function (string $decl) {
    [$out, $codes] = w7css(".pkg-w{{$decl}}");
    expect($codes)->toBe([])->and($out)->not->toBeNull();
})->with(['48px' => 'width:48px', 'max-width 37.5rem' => 'max-width:37.5rem', 'height auto' => 'height:auto', 'full' => 'width:100%;max-width:none', 'min-height' => 'min-height:100svh', 'max() of big' => 'width:max(30px,60px)']);

// Rule 3: decorative pkg-* elements ------------------------------------------------------------------------------------

it('limits z-index to -1..10 and pointer-events to none on decorative elements', function (string $decl, bool $ok) {
    [$out, $codes] = w7css(".pkg-deco{{$decl}}");
    expect($out !== null)->toBe($ok);
    if (! $ok) {
        expect($codes)->toBe(['css_forbidden_property']);
    }
})->with([
    ['z-index:-1', true], ['z-index:10', true], ['z-index:0', true], ['z-index:-2', false], ['z-index:11', false],
    ['pointer-events:none', true], ['pointer-events:auto', false], ['pointer-events:all', false],
]);

// Rule 4: state selectors (N-4) ----------------------------------------------------------------------------------------

it('refuses layout properties inside rules that use pointer or focus states', function (string $selector, string $decl) {
    [$out, $codes] = w7css("{$selector}{{$decl}}");
    expect($out)->toBeNull()->and($codes)->toContain('state_selector_property');
})->with([
    'overlay display (N-4)' => ['.pkg-w:hover + .pkg-ovbox .pkg-ova', 'display:block'],
    'hover position' => ['.pkg-a:hover', 'position:absolute'],
    'hover z-index' => ['.pkg-a:hover', 'z-index:3'],
    'focus-within size' => ['.pkg-w:focus-within .pkg-a', 'width:100%'],
    'active transform' => ['.pkg-a:active', 'transform:scale(2)'],
    'hover opacity' => ['.pkg-a:hover', 'opacity:0'],
    'focus-visible margin' => ['.pkg-a:focus-visible', 'margin-top:-100px'],
    'hover pointer-events' => ['.pkg-a:hover', 'pointer-events:none'],
    'hover content' => ['.pkg-a:hover::after', 'content:""'],
    'state inside :is()' => ['.pkg-a:is(:hover) .pkg-b', 'visibility:visible'],
    'hover custom property' => ['.pkg-a:hover', '--pkg-x:1'],
    'hover transition' => ['.pkg-a:hover', 'transition:all 1s'],
    'hover on a generic subject' => ['a:hover', 'padding:200px'],
]);

it('allows colors and shadows inside state rules, and any property in stateless rules', function (string $selector, string $decl) {
    [$out, $codes] = w7css("{$selector}{{$decl}}");
    expect($codes)->toBe([])->and($out)->not->toBeNull();
})->with([
    'hover colors' => ['.pkg-a:hover', 'color:#333;background-color:#fff;border-color:red;box-shadow:0 1px 2px #000'],
    'focus outline color' => ['.pkg-a:focus-visible', 'outline-color:#7b2d5b;text-decoration-color:red'],

    'stateless layout' => ['.pkg-a', 'display:block;position:relative;z-index:2;margin-top:1rem'],
    'first-child is not a state' => ['.pkg-a:first-child', 'margin-top:0'],
]);

it('keeps refusing :target, :checked and :visited', function (string $pseudo) {
    [$out, $codes] = w7css(".pkg-a:{$pseudo}{color:red}");
    expect($out)->toBeNull()->and($codes)->toBe(['css_selector_forbidden']);
})->with(['target', 'checked', 'visited']);

// Rule 5: behaviors on the music (N-5) ---------------------------------------------------------------------------------

function w7ast(string $html, string $type = 'music'): array
{
    $report = new Report;
    $out = (new HtmlAstBuilder)->build($html, "sections/{$type}.html", 'section', $type, [], $report);

    return [$out, $report->errorCodes()];
}

it('refuses data-reveal, data-parallax and data-carousel on the music and its ancestors', function (string $html) {
    [$out, $codes] = w7ast($html);
    expect($out)->toBeNull()->and($codes)->toBe(['behavior_on_music']);
})->with([
    'reveal on the container (N-5)' => ['<div class="pkg-m" data-reveal="zoom"><q-music></q-music></div>'],
    'reveal on a distant ancestor' => ['<div data-reveal="fade"><div><section><q-music></q-music></section></div></div>'],
    'reveal on the block itself' => ['<q-music data-reveal="rise"></q-music>'],
    'parallax on the container' => ['<div data-parallax="soft"><q-music></q-music></div>'],
    'carousel on the container' => ['<ul data-carousel="snap"><li><q-music></q-music></li></ul>'],
]);

it('allows behaviors beside the music and on other blocks', function (string $html) {
    [$out, $codes] = w7ast($html);
    expect($codes)->toBe([])->and($out)->not->toBeNull();
})->with([
    'sibling reveal' => ['<div class="pkg-m"><p data-reveal="rise">hola</p><q-music></q-music></div>'],
    'reveal in another block container' => ['<div data-reveal="fade"><q-countdown></q-countdown></div><q-music></q-music>'],
    'music without behaviors' => ['<div class="pkg-m"><q-music variant="button"></q-music></div>'],
]);

// Rule 6: fast infinite animations (N-6) -------------------------------------------------------------------------------

it('refuses infinite animations faster than 1s, without a duration, or on containers and full-screen elements', function (string $rule) {
    [$out, $codes] = w7css("@keyframes f{50%{opacity:0}} {$rule}");
    expect($out)->toBeNull()->and($codes)->toContain('animation_too_fast');
})->with([
    'shorthand 0.1s (N-6)' => ['.pkg-ova{animation:f .1s infinite}'],
    'shorthand 999ms' => ['.pkg-ova{animation:f 999ms infinite}'],
    'longhand duration' => ['.pkg-ova{animation-name:f;animation-duration:.33s;animation-iteration-count:infinite}'],
    'infinite with no duration in the rule' => ['.pkg-ova{animation-name:f;animation-iteration-count:infinite}'],
    'second animation too fast' => ['.pkg-ova{animation:f 3s infinite, f .2s infinite}'],
    'on a wrapper' => ['.pkg-w{animation:f 3s infinite}'],
    'on the root' => ['.inv-root{animation:f 3s infinite}'],
    'on a generic selector' => ['div{animation:f 3s infinite}'],
    'full screen' => ['.pkg-ova{width:100vw;height:100vh;animation:f 3s infinite}'],
    'duration set in another rule' => ['.pkg-a{animation:f 3s infinite} .pkg-b{animation-duration:.1s}'],
]);

it('allows slow infinite animations on decoration and fast finite ones', function (string $rule) {
    [$out, $codes] = w7css("@keyframes f{50%{opacity:0}} {$rule}");
    expect($codes)->toBe([])->and($out)->not->toBeNull();
})->with([
    'exactly 1s' => ['.pkg-ova{animation:f 1s infinite}'],
    'slow longhand' => ['.pkg-ova{animation-name:f;animation-duration:3s;animation-iteration-count:infinite}'],
    'fast but finite' => ['.pkg-ova{animation:f .2s 3}'],
    'pseudo-element of a wrapper' => ['.pkg-w::before{animation:f 2s infinite}'],
]);

// Rule 7: declared contrast and accumulated opacity (N-7) --------------------------------------------------------------

function w7package(string $css, string $rsvp = '<div class="pkg-rsvp-wrap"><h2 class="pkg-t">{{ section.title }}</h2><q-rsvp variant="paper" class="pkg-rsvp"></q-rsvp></div>'): array
{
    return PackageBuilder::good()->with('theme.css', $css)->with('sections/rsvp.html', $rsvp)->validate();
}

it('refuses text whose declared color is almost the background', function (string $css) {
    $r = w7package($css);
    expect(PackageBuilder::errors($r))->toContain('low_contrast_declared');
})->with([
    'same rule' => ['.pkg-t{color:#fefefe;background-color:#fff}'],
    'background on an ancestor' => ['.pkg-rsvp-wrap{background:#ffffff} .pkg-t{color:#fdfdfd}'],
    'block hook over a wrapper background (N-7)' => ['.pkg-rsvp-wrap{background-color:white} .inv-q--rsvp{color:#fefefe}'],
    'against the default surface' => ['.pkg-t{color:var(--inv-surface)}'],
    'through a --pkg variable' => ['.pkg-t{--pkg-ink:#eeeeee;color:var(--pkg-ink);background-color:#f0f0f0}'],
    'rgb and hsl' => ['.pkg-t{color:rgb(250,250,250);background-color:hsl(0,0%,100%)}'],
]);

it('accepts text with a readable declared contrast and ignores what it cannot resolve', function (string $css) {
    $r = w7package($css);
    expect(PackageBuilder::errors($r))->not->toContain('low_contrast_declared');
})->with([
    'dark on light' => ['.pkg-t{color:#222;background-color:#fff}'],
    'tokens' => ['.pkg-t{color:var(--inv-ink)} .pkg-rsvp-wrap{background:var(--inv-surface-alt)}'],
    'gradient background is unknown' => ['.pkg-rsvp-wrap{background:linear-gradient(#fff,#fefefe)} .pkg-t{color:#fff}'],
    'calc is unknown' => ['.pkg-t{color:#fff;background-color:var(--pkg-unknown)}'],
]);

it('refuses text under an accumulated declared opacity below 0.4, and allows moderate chains', function (string $css, bool $bad) {
    $r = w7package($css, '<div class="pkg-a"><div class="pkg-b"><p class="pkg-c">hola</p></div></div><div class="pkg-rsvp-wrap"><q-rsvp></q-rsvp></div>');
    expect(in_array('low_contrast_declared', PackageBuilder::errors($r), true))->toBe($bad);
})->with([
    'four nested levels' => ['.pkg-a{opacity:.7} .pkg-b{opacity:.7} .pkg-c{opacity:.7}', true],
    'single faint text' => ['.pkg-c{opacity:.3}', true],
    'two levels' => ['.pkg-a{opacity:.8} .pkg-b{opacity:.8}', false],
    'exactly 0.4' => ['.pkg-a{opacity:.5} .pkg-c{opacity:.8}', false],
    'decorative without text' => ['.pkg-x{opacity:.1}', false],
]);
