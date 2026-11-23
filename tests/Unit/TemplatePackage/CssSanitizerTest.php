<?php

use App\Support\TemplatePackage\CssSanitizer;
use App\Support\TemplatePackage\Report;

function css(string $source, array $kinds = [], array $assets = ['assets/img/a.webp', 'assets/img/m.svg'], array $families = ['Great Vibes']): array
{
    $report = new Report;
    $out = (new CssSanitizer)->sanitize($source, 'botanical', 'theme.css', $report, $assets, $families, $kinds);

    return [$out, $report->errorCodes(), $report];
}

const SCOPE = '[data-inv-theme="botanical"]';

it('scopes every selector under the package attribute', function (string $in, string $expected) {
    [$out, $codes] = css($in . '{color:red}');
    expect($codes)->toBe([])->and($out)->toBe(str_replace('SCOPE', SCOPE, $expected) . '{color:red}');
})->with([
    'class' => ['.pkg-a', 'SCOPE .pkg-a'],
    'selector list' => ['.pkg-a, .pkg-b', 'SCOPE .pkg-a,SCOPE .pkg-b'],
    'descendant and child' => ['.pkg-a > .pkg-b .pkg-c', 'SCOPE .pkg-a>.pkg-b .pkg-c'],
    'element' => ['h1', 'SCOPE h1'],
    'universal' => ['*', 'SCOPE *'],
    'pseudo element' => ['.pkg-a::before', 'SCOPE .pkg-a::before'],
    'legacy single colon pseudo element' => ['.pkg-a:after', 'SCOPE .pkg-a::after'],
    'not and is' => ['.pkg-a:not(.pkg-b):is(.pkg-c, .pkg-d)', 'SCOPE .pkg-a:not(.pkg-b):is(.pkg-c,.pkg-d)'],
    'nth-child' => ['li:nth-child( 2n + 1 )', 'SCOPE li:nth-child(2n+1)'],
    ':root becomes the scope element' => [':root', 'SCOPE'],
    'html becomes the scope element' => ['html', 'SCOPE'],
    'body becomes the scope element' => ['body', 'SCOPE'],
    'body with class' => ['body.pkg-dark', 'SCOPE.pkg-dark'],
    ':root with descendant' => [':root .pkg-a', 'SCOPE .pkg-a'],
    'html body descendant is refused later' => ['body > .pkg-a', 'SCOPE>.pkg-a'],
    'explicit .inv-root' => ['.inv-root .pkg-a', '.inv-root[data-inv-theme="botanical"] .pkg-a'],
    'data attribute' => ['[data-inv-stage="save_the_date"] .pkg-a', 'SCOPE [data-inv-stage="save_the_date"] .pkg-a'],
    'id with pkg prefix' => ['#pkg-hero', 'SCOPE #pkg-hero'],
    'hover' => ['.pkg-a:hover', 'SCOPE .pkg-a:hover'],
    'adjacent and sibling' => ['.pkg-a + .pkg-b ~ .pkg-c', 'SCOPE .pkg-a+.pkg-b~.pkg-c'],
]);

it('rejects selectors outside the closed grammar', function (string $selector) {
    [$out, $codes] = css($selector . '{color:red}');
    // Either our grammar or the strict parser stops it; what matters is that nothing comes out.
    expect($out)->toBeNull()->and(array_diff($codes, ['css_selector_forbidden', 'css_parse_error', 'css_forbidden_property']))->toBe([])->and($codes)->not->toBe([]);
})->with([
    ':has' => ['.pkg-a:has(img)'],
    ':host' => [':host'],
    '::slotted' => ['::slotted(.pkg-a)'],
    ':global' => [':global(.pkg-a)'],
    'plain id' => ['#login'],
    'attribute outside list' => ['input[name=x]'],
    'href attribute' => ['a[href^="javascript:"]'],
    'class attribute fishing for engine classes' => ['[class*="inv-r"]'],
    'q tag' => ['q-rsvp'],
    'unknown tag' => ['marquee'],
    'script tag' => ['script'],
    'body in the middle' => ['.pkg-a body'],
    'html in the middle' => ['.pkg-a > html'],
    ':root in the middle' => ['.pkg-a :root'],
    'more than six compounds' => ['a b c d e f g'],
    'namespace' => ['svg|a'],
    'double dot' => ['..a'],
    'non ascii class' => [".\u{0430}bc"],
    'unknown pseudo-class' => ['.pkg-a:visited'],
    ':checked' => ['.pkg-a:checked'],
    'unknown pseudo-element' => ['.pkg-a::-webkit-scrollbar'],
    'nested function pseudo' => ['.pkg-a:nth-child(2 of .pkg-b)'],
    'selector with brace' => ['.pkg-a}'],
    'trailing combinator' => ['.pkg-a >'],
    'leading combinator' => ['> .pkg-a'],
]);

it('rejects forbidden at-rules', function (string $source, string $code) {
    [$out, $codes] = css($source);
    // `a|b` = either code: the strict parser or the at-rule check stops it first.
    expect($out)->toBeNull()->and(array_intersect($codes, explode('|', $code)))->not->toBe([]);
})->with([
    '@import url' => ['@import url("http://evil/x.css"); .pkg-a{color:red}', 'css_import'],
    '@import string' => ['@import "x.css";', 'css_import'],
    '@import uppercase' => ['@IMPORT url(x.css);', 'css_import'],
    '@import with comment' => ['@/**/import "x.css";', 'css_parse_error'],
    '@font-face external' => ['@font-face{font-family:x;src:url(http://evil/x.woff2)}', 'css_forbidden_at_rule'],
    '@font-face local assets' => ['@font-face{font-family:x;src:url(assets/f.woff2)}', 'css_forbidden_at_rule'],
    '@namespace' => ['@namespace svg url(http://www.w3.org/2000/svg);', 'css_forbidden_at_rule'],
    '@layer' => ['@layer base{.pkg-a{color:red}}', 'css_forbidden_at_rule'],
    '@property' => ['@property --x{syntax:"<length>";inherits:false;initial-value:0}', 'css_forbidden_at_rule|css_parse_error'],
    '@page' => ['@page{margin:0}', 'css_forbidden_at_rule'],
    '@charset not utf-8' => ['@charset "iso-8859-1"; .pkg-a{color:red}', 'css_forbidden_at_rule'],
    '@document' => ['@document url(http://x){.pkg-a{color:red}}', 'css_forbidden_at_rule|css_parse_error'],
    'unknown statement at-rule' => ['@foo bar;', 'css_forbidden_at_rule'],
    'unknown block at-rule' => ['@foo bar{.pkg-a{color:red}}', 'css_parse_error'],
    '@media color scheme' => ['@media (prefers-color-scheme:dark){.pkg-a{color:red}}', 'css_forbidden_at_rule'],
    '@media print' => ['@media print{.pkg-a{color:red}}', 'css_forbidden_at_rule'],
    '@media hostile condition' => ['@media (min-width:1px) and (foo:expression(alert(1))){.pkg-a{color:red}}', 'css_forbidden_property'],
    '@supports with url' => ['@supports (mask-image:url(http://x)){.pkg-a{color:red}}', 'css_forbidden_at_rule'],
    '@supports selector()' => ['@supports selector(:has(a)){.pkg-a{color:red}}', 'css_forbidden_at_rule'],
    '@container hostile' => ['@container (min-width:1px) or url(x){.pkg-a{color:red}}', 'css_forbidden_at_rule'],
    '@keyframes bad step' => ['@keyframes x{foo{opacity:0}}', 'css_selector_forbidden'],
    'deeply nested at-rules' => ['@media (min-width:1px){@media (min-width:2px){@media (min-width:3px){@media (min-width:4px){.pkg-a{color:red}}}}}', 'css_forbidden_at_rule'],
]);

it('rejects every execution vector and escape trick', function (string $source, string $code) {
    [$out, $codes] = css($source);
    expect($out)->toBeNull()->and($codes)->toContain($code);
})->with([
    'escaped url' => ['.pkg-a{background:\75rl(http://evil/x)}', 'css_backslash'],
    'escaped url 2' => ['.pkg-a{background:u\72l(http://evil/x)}', 'css_backslash'],
    'escaped import' => ['\40import "x.css";', 'css_backslash'],
    'escaped javascript' => ['.pkg-a{background:url(\6aavascript:alert(1))}', 'css_backslash'],
    'escaped selector' => ['.pkg-a\:b{color:red}', 'css_backslash'],
    'unicode escape in string' => ['.pkg-a::before{content:"\201C"}', 'css_backslash'],
    'comment split url' => ['.pkg-a{background:u/**/rl(http://x)}', 'css_parse_error'],
    'expression' => ['.pkg-a{width:expression(alert(1))}', 'css_forbidden_property'],
    'expression spaced' => ['.pkg-a{width:EXPRESSION  (alert(1))}', 'css_forbidden_property'],
    'behavior' => ['.pkg-a{behavior:url(x.htc)}', 'css_forbidden_property'],
    '-moz-binding' => ['.pkg-a{-moz-binding:url("http://evil/x.xml#y")}', 'css_forbidden_property'],
    'javascript url' => ['.pkg-a{background:url(javascript:alert(1))}', 'css_forbidden_property'],
    'javascript quoted' => ['.pkg-a{background:url("javascript:alert(1)")}', 'css_forbidden_property'],
    'vbscript' => ['.pkg-a{background:url(vbscript:msgbox(1))}', 'css_forbidden_property'],
    'closing style tag' => ['.pkg-a::before{content:"</style><script>alert(1)</script>"}', 'css_parse_error'],
    'html comment' => ['<!-- .pkg-a{color:red} -->', 'css_parse_error'],
    'NUL byte' => ["bo\0dy{color:red}", 'css_parse_error'],
    'bidi control' => ["/* \u{202E} */ .pkg-a{color:red}", 'css_parse_error'],
    'zero width space' => ["\u{200B}.pkg-a{color:red}", 'css_parse_error'],
    'BOM' => ["\xEF\xBB\xBF.a{color:red}", 'css_parse_error'],
    'invalid utf8' => ["/* \xFF */ .pkg-a{color:red}", 'css_parse_error'],
    'unterminated block' => ['.pkg-a{color:red', 'css_parse_error'],
    'stray brace' => ['.pkg-a{color:red}} .pkg-b{color:blue}', 'css_parse_error'],
    'unterminated string' => ['.pkg-a{content:"abc}', 'css_parse_error'],
    'css nesting' => ['.pkg-a{color:red; &:hover{color:blue}}', 'css_parse_error'],
    '!important' => ['.pkg-a{color:red !important}', 'css_important'],
    '!important no space' => ['.pkg-a{color:red!important}', 'css_important'],
]);

it('only lets url() point to existing files of assets/', function (string $value, ?string $code, string $prop = 'background-image') {
    [$out, $codes] = css(".pkg-a{{$prop}:{$value}}");
    $code === null ? expect($codes)->toBe([]) : expect($out)->toBeNull()->and($codes)->toContain($code);
})->with([
    'ok unquoted' => ['url(assets/img/a.webp)', null],
    'ok quoted' => ['url("assets/img/a.webp")', null],
    'ok mask' => ['url(assets/img/m.svg)', null, 'mask-image'],
    'ok gradient list' => ['url(assets/img/a.webp), linear-gradient(red, blue)', null],
    'absolute http' => ['url(http://evil/x.png)', 'css_url_external'],
    'absolute https' => ['url("https://evil/x.png")', 'css_url_external'],
    'protocol relative' => ['url(//evil/x.png)', 'css_url_external'],
    'data uri' => ['url(data:image/svg+xml;base64,AAAA)', 'css_url_external'],
    'parent traversal' => ['url(assets/../manifest.json)', 'css_url_external'],
    'root relative' => ['url(/etc/passwd)', 'css_url_external'],
    'outside assets' => ['url(theme.css)', 'css_url_external'],
    'dot slash' => ['url(./assets/img/a.webp)', 'css_url_external'],
    'double slash' => ['url(assets//img/a.webp)', 'css_url_external'],
    'fragment' => ['url(assets/img/a.webp#x)', 'css_url_external'],
    'query' => ['url(assets/img/a.webp?x=1)', 'css_url_external'],
    'percent encoded dots' => ['url(assets/%2e%2e/a.webp)', 'css_url_external'],
    'missing file' => ['url(assets/img/nope.webp)', 'css_url_external'],
    'url in content' => ['url(assets/img/a.webp)', 'css_url_external', 'content'],
    'url in cursor' => ['url(assets/img/a.webp), auto', 'css_url_external', 'cursor'],
    'url in font-family' => ['url(assets/img/a.webp)', 'css_url_external', 'font-family'],
    'image-set' => ['image-set("assets/img/a.webp" 1x)', 'css_forbidden_property'],
]);

it('rejects properties and values outside the white list', function (string $decl, string $code) {
    [$out, $codes] = css(".pkg-a{{$decl}}");
    expect($out)->toBeNull()->and($codes)->toContain($code);
})->with([
    'position fixed' => ['position:fixed', 'css_forbidden_property'],
    'position sticky' => ['position:sticky', 'css_forbidden_property'],
    'z-index 11' => ['z-index:11', 'css_forbidden_property'],
    'z-index huge' => ['z-index:2147483647', 'css_forbidden_property'],
    'z-index calc' => ['z-index:calc(5 + 99)', 'css_forbidden_property'],
    'content text' => ['content:"Click here to win"', 'css_forbidden_property'],
    'content attr' => ['content:attr(data-x)', 'css_forbidden_property'],
    'cursor url' => ['cursor:url(assets/img/a.webp), auto', 'css_url_external'],
    'zoom' => ['zoom:5', 'css_forbidden_property'],
    'all' => ['all:unset', 'css_forbidden_property'],
    'will-change' => ['will-change:transform', 'css_forbidden_property'],
    'unknown property' => ['foo:bar', 'css_forbidden_property'],
    '-ms prefix' => ['-ms-filter:"progid:DXImageTransform"', 'css_forbidden_property'],
    'visibility hidden' => ['visibility:hidden', 'css_forbidden_property'],
    'background fixed' => ['background:url(assets/img/a.webp) fixed', 'css_forbidden_property'],
    'duration too long' => ['transition:opacity 5s', 'css_forbidden_property'],
    'delay too long' => ['animation:x 1s 9s', 'css_forbidden_property'],
    'font not declared' => ['font-family:"Comic Sans MS"', 'font_not_self_hosted'],
    'unknown function' => ['width:attr(data-x px)', 'css_forbidden_property'],
    'env-like unknown function' => ['width:foo(1)', 'css_forbidden_property'],
    'var of foreign name' => ['color:var(--other)', 'css_forbidden_property'],
    'var of engine private' => ['color:var(--tw-ring)', 'css_forbidden_property'],
    'declaring --inv' => ['--inv-primary:red', 'css_inv_var'],
    'declaring foreign custom property' => ['--x:red', 'css_forbidden_property'],
    'string in color' => ['color:"red"', 'css_forbidden_property'],
    'url hidden in var fallback' => ['color:var(--inv-ink, url(http://evil/x))', 'css_url_external'],
    'url stored in a custom property' => ['--pkg-x:url(http://evil/x)', 'css_url_external'],
    'url in font shorthand' => ['font:12px url(http://evil/x)', 'css_url_external'],
    '-webkit-image-set' => ['background:-webkit-image-set(url(assets/img/a.webp) 1x)', 'css_forbidden_property'],
    'src()' => ['background:src("http://evil/x")', 'css_forbidden_property'],
]);

it('accepts the allowed forms', function (string $decl) {
    [$out, $codes] = css(".pkg-a{{$decl}}");
    expect($codes)->toBe([])->and($out)->not->toBeNull();
})->with([
    ['position:absolute'], ['position:relative'], ['z-index:10'], ['z-index:-1'], ['content:""'], ['content:none'],
    ['--pkg-gap:1rem'], ['color:var(--inv-ink, #000)'], ['width:calc(100% - var(--pkg-gap))'],
    ['font-family:"Great Vibes",cursive'], ['font-family:Fraunces,serif'], ['transition:opacity .3s ease-out,translate 4s'],
    ['animation:fade .6s both'], ['transform:translate(-50%,-50%) rotate(3deg)'], ['aspect-ratio:4/5'],
    ['mask:url(assets/img/m.svg) center/contain no-repeat'], ['background:radial-gradient(circle at 30% 20%,var(--inv-wash) 0,transparent 60%)'],
    ['color:color-mix(in srgb,var(--inv-accent) 60%,var(--inv-ink))'], ['filter:drop-shadow(0 1px 2px rgb(0 0 0 / .2))'],
    ['border-radius:58% 42% 55% 45% / 45% 55% 42% 58%'], ['cursor:pointer'], ['display:none'], ['pointer-events:none'],
    ['font:italic 1rem "Great Vibes",cursive'], ['padding:max(1rem,env(safe-area-inset-top))'], ['grid-template-areas:"a b" "c d"'], ['clip-path:circle(50% at 50% 50%)'],
]);

it('accepts and drops a utf-8 @charset', function () {
    [$out, $codes] = css('@charset "utf-8"; .pkg-a{color:red}');
    expect($codes)->toBe([])->and($out)->toBe(SCOPE . ' .pkg-a{color:red}');
});

it('allows the nested at-rules the design needs', function () {
    [$out, $codes] = css('@media (min-width:640px) and (prefers-reduced-motion:no-preference){@supports (mask-image:none){.pkg-a{color:red}}} @container (min-width:400px){.pkg-b{color:blue}} @media (width >= 600px){.pkg-c{color:red}}');
    expect($codes)->toBe([])
        ->and($out)->toContain('@media (min-width:640px) and (prefers-reduced-motion:no-preference){@supports (mask-image:none){' . SCOPE . ' .pkg-a{color:red}}}')
        ->and($out)->toContain('@container (min-width:400px){' . SCOPE . ' .pkg-b{color:blue}}');
});

it('prefixes keyframes and rewrites every reference', function () {
    [$out, $codes] = css('.pkg-a{animation:fade 1s ease both}.pkg-b{animation-name:fade}.pkg-c{animation:slide 2s,fade 1s}@keyframes fade{from{opacity:0}to{opacity:1}}@keyframes slide{0%{opacity:0}50%{opacity:.5}100%{opacity:1}}');
    expect($codes)->toBe([])
        ->and($out)->toContain('@keyframes botanical-fade')->toContain('@keyframes botanical-slide')
        ->and($out)->toContain('animation:botanical-fade 1s ease both')->toContain('animation-name:botanical-fade')
        ->and($out)->toContain('animation:botanical-slide 2s,botanical-fade 1s');
});

it('does not let a rule hide or disable a system block', function (string $selector, string $decl) {
    [$out, $codes] = css("{$selector}{{$decl}}", ['pkg-rsvp' => 'system', 'pkg-card' => 'wrapper', '#pkg-form' => 'system']);
    expect($out)->toBeNull()->and(array_intersect($codes, ['css_forbidden_property', 'block_container_property', 'block_container_too_small']))->not->toBeEmpty();
})->with([
    'inv-rsvp display none' => ['.inv-q--rsvp', 'display:none'],
    'inv-rsvp opacity' => ['.inv-q--rsvp', 'opacity:0'],
    'inv-rsvp pointer events' => ['.inv-q--rsvp', 'pointer-events:none'],
    'inv-rsvp position' => ['.inv-q--rsvp', 'position:absolute'],
    'inv-rsvp transform' => ['.inv-q--rsvp', 'transform:scale(0)'],
    'descendant of inv-rsvp' => ['.inv-q--rsvp .inv-q--cta', 'visibility:hidden'],
    'watermark display' => ['.inv-q--sections', 'display:none'],
    'watermark size' => ['.inv-q--sections', 'font-size:0'],
    'language switch' => ['.inv-q--language-switch', 'display:none'],
    'music' => ['.inv-q--music', 'display:none'],
    'button' => ['.inv-q--cta', 'width:0'],
    'package class on a <q-rsvp>' => ['.pkg-rsvp', 'display:none'],
    'section hook hides the section' => ['[data-inv-section="rsvp"]', 'display:none'],
    'descendant of a wrapper by bare tag' => ['.pkg-card div', 'display:none'],
    'attribute selector on engine internals' => ['[role="button"]', 'display:none'],
    'package id on a <q-rsvp>' => ['#pkg-form', 'clip-path:inset(100%)'],
    'wrapper hides the block' => ['.pkg-card', 'display:none'],
    'wrapper clips the block' => ['.pkg-card', 'clip-path:inset(50%)'],
    'wrapper zero height' => ['.pkg-card', 'max-height:0'],
    'wrapper blocks clicks' => ['.pkg-card', 'pointer-events:none'],
    'wrapper masks' => ['.pkg-card', 'mask-image:url(assets/img/m.svg)'],
    'universal subject hides' => ['*', 'display:none'],
    'bare tag hides' => ['div', 'display:none'],
    'bare tag moves' => ['section', 'position:absolute'],
    'bare tag animates away' => ['p', 'transform:translateX(-999px)'],
]);

it('lets a system block and its wrapper be styled visually', function (string $selector, string $decl) {
    [$out, $codes] = css("{$selector}{{$decl}}", ['pkg-rsvp' => 'system', 'pkg-card' => 'wrapper']);
    expect($codes)->toBe([])->and($out)->not->toBeNull();
})->with([
    ['.inv-q--rsvp', 'background:var(--inv-surface-alt)'],
    ['.inv-q--rsvp', 'border-radius:1rem'],
    ['.inv-q--cta:hover', 'background:red'],
    ['.pkg-rsvp', 'padding:2rem'],
    ['.pkg-card', 'display:grid'],
    ['.pkg-card', 'background:url(assets/img/a.webp)'],
    ['h1', 'color:red'],
    ['img', 'width:100%'],
]);

it('enforces limits: size and rule count', function () {
    [$out, $codes] = css(str_repeat('.pkg-a{color:red}', 16000));
    expect($out)->toBeNull()->and($codes)->toContain('css_size');

    [$out2, $codes2] = css('.pkg-a{color:red}' . str_repeat('/* ' . str_repeat('x', 100) . ' */', 2100));
    expect($out2)->toBeNull()->and($codes2)->toContain('css_size');
});

it('warns about costly or infinite effects without blocking', function () {
    [$out, , $report] = css('.pkg-a{filter:blur(4px);animation:spin 3s infinite}@keyframes spin{to{opacity:1}}');
    expect($out)->not->toBeNull()->and($report->warningCodes())->toBe(['css_costly_property']);
});

it('output invariants hold for every accepted stylesheet (property test over generated CSS)', function () {
    mt_srand(1234);
    $props = ['color:red', 'background:url(assets/img/a.webp)', 'margin:0 auto', 'transform:rotate(3deg)', 'width:calc(1px + 2rem)', 'animation:fade 1s', 'z-index:3', 'content:""', 'mask-image:url(assets/img/m.svg)'];
    $sels = ['.pkg-a', '.pkg-t .pkg-h1', 'body::before', ':root::after', '.pkg-a > .pkg-b', '.pkg-a:first-child::after', '.inv-root .pkg-x', '[data-inv-stage="x"] p.pkg-t', '.pkg-a:not(.pkg-b)', 'li.pkg-t:nth-child(2n+1)'];
    $accepted = 0;
    for ($i = 0; $i < 300; $i++) {
        $src = '';
        for ($r = 0, $n = mt_rand(1, 6); $r < $n; $r++) {
            $body = implode(';', array_map(fn () => $props[array_rand($props)], range(1, mt_rand(1, 4))));
            $rule = $sels[array_rand($sels)] . '{' . $body . '}';
            $src .= mt_rand(0, 4) === 0 ? '@media (min-width:' . mt_rand(1, 900) . 'px){' . $rule . '}' : $rule;
        }
        $src .= '@keyframes fade{from{opacity:0}to{opacity:1}}';
        [$out, $codes, $rep] = css($src);
        expect($codes)->toBe([], $src . ' => ' . json_encode($rep->toArray()['errors']));
        $accepted++;
        expect($out)->not->toMatch('/@import|@font-face|expression|javascript:|<|\\\\|!important/i');
        // every selector prelude starts with the scope; every url() is a quoted assets/ path
        preg_match_all('/(?:^|\})([^{}@]+)\{/', $out, $m);
        foreach ($m[1] as $prelude) {
            foreach (explode(',', $prelude) as $sel) {
                if (preg_match('/^(from|to|\d+%)$/', $sel)) {
                    continue; // keyframe steps
                }
                expect($sel)->toContain(SCOPE);
            }
        }
        preg_match_all('/url\(([^)]*)\)/', $out, $u);
        foreach ($u[1] as $arg) {
            expect($arg)->toMatch('/^"assets\/[a-z0-9._\/-]+"$/');
        }
        [$again] = css($out);
        expect($again)->toBe($out);
    }
    expect($accepted)->toBe(300);
});

it('fuzz: random garbage never throws and never produces output with a dangerous token', function () {
    mt_srand(99);
    $alphabet = ['{', '}', ';', ':', '@', '.', '#', '(', ')', '"', "'", '\\', '/', '*', '!', 'url', 'import', 'a', 'b', 'color', 'red', 'expression', ' ', "\n", 'javascript', '<', '>', '-', '0', '%', 'assets/', 'http://'];
    for ($i = 0; $i < 800; $i++) {
        $src = '';
        for ($j = 0, $n = mt_rand(1, 60); $j < $n; $j++) {
            $src .= $alphabet[array_rand($alphabet)];
        }
        [$out] = css($src);
        if ($out !== null) {
            expect($out)->not->toMatch('/@import|expression|javascript:|<|\\\\|!important/i');
        }
    }
    expect(true)->toBeTrue();
});

it('does not count colour channels as shadow or outline measures', function (string $decl) {
    [$out, $codes] = css(".pkg-card{{$decl}}", ['pkg-card' => 'wrapper']);
    expect($codes)->toBe([])->and($out)->not->toBeNull();
})->with([
    ['box-shadow:0 0 20px rgba(40,221,238,.4)'],
    ['box-shadow:0 0 20px rgba(40,221,238,.4),0 0 42px rgba(255,27,169,.3)'],
    ['box-shadow:0 0 14px #28ddee66'],
    ['box-shadow:0 0 14px #fff'],
    ['box-shadow:0 0 20px hsla(330,100%,60%,.4)'],
    ['box-shadow:0 0 20px oklch(0.8 0.2 330)'],
    ['box-shadow:0 0 20px color-mix(in srgb,#ff00ff 50%,red)'],
    ['text-shadow:0 2px 20px rgba(239,179,69,.19)'],
    ['outline:2px solid rgb(255 27 169)'],
    ['box-shadow:0 4px 12px rgba(0,0,0,.2)'],
]);

it('still rejects oversized or dynamic shadows and outlines', function (string $decl) {
    [, $codes] = css(".pkg-card{{$decl}}", ['pkg-card' => 'wrapper']);
    expect($codes)->toContain('css_forbidden_property');
})->with([
    ['box-shadow:0 0 150px rgba(40,221,238,.4)'],
    ['box-shadow:120px 0 10px rgba(40,221,238,.4)'],
    ['text-shadow:0 0 101px #28ddee'],
    ['outline-width:101px'],
    ['outline-offset:120px'],
    ['outline:120px solid rgb(255,27,169)'],
    ['box-shadow:0 0 9rem rgba(40,221,238,.4)'],
    ['box-shadow:0 0 9em hsla(330,100%,60%,.4)'],
    ['box-shadow:0 0 calc(10px + 2px) rgba(40,221,238,.4)'],
    ['box-shadow:0 0 var(--pkg-x) rgba(40,221,238,.4)'],
]);

it('applies the drop-shadow() limit to the offsets, not to the colour channels', function () {
    [$out, $codes] = css('.pkg-a{filter:drop-shadow(0 0 20px rgba(255,27,169,.5))}');
    expect($codes)->toBe([])->and($out)->not->toBeNull();

    [, $codes] = css('.pkg-a{filter:drop-shadow(0 0 150px rgba(255,27,169,.5))}');
    expect($codes)->toContain('css_forbidden_property');
});
