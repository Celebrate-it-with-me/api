<?php

use App\Support\TemplatePackage\Report;
use App\Support\TemplatePackage\SvgSanitizer;

function svg(string $inner, string $attrs = ''): string
{
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 50" ' . $attrs . '>' . $inner . '</svg>';
}

function runSvg(string $source): array
{
    $report = new Report;
    $out = (new SvgSanitizer)->sanitize($source, 'a.svg', $report);

    return [$out, $report->errorCodes(), $report];
}

it('rebuilds a clean SVG from the white list', function () {
    $in = svg('<title>t</title><defs><linearGradient id="g"><stop offset="0" stop-color="#fff"/><stop offset="1" stop-color="#000"/></linearGradient>'
        . '<clipPath id="c"><rect width="50" height="50"/></clipPath><filter id="f"><feGaussianBlur stdDeviation="3"/></filter></defs>'
        . '<g clip-path="url(#c)" filter="url(#f)"><path id="p" d="M0 0 L10 10 C 1 2, 3 4, 5 6 Z" fill="url(#g)" stroke="currentColor" stroke-width="2" transform="translate(1,2) rotate(3)"/></g><use href="#p" x="5"/>');
    [$out, $codes] = runSvg($in);

    expect($codes)->toBe([])
        ->and($out)->toStartWith('<svg xmlns="http://www.w3.org/2000/svg"')
        ->and($out)->toContain('viewBox="0 0 100 50"')
        ->and($out)->not->toContain('<?xml');
});

it('drops fixed width and height and comments but keeps the viewBox', function () {
    [$out, $codes] = runSvg('<svg xmlns="http://www.w3.org/2000/svg" width="900" height="400" viewBox="0 0 9 4"><!-- hi --><path d="M0 0L1 1"/></svg>');
    expect($codes)->toBe([])->and($out)->not->toContain('width=')->and($out)->not->toContain('hi');
});

it('rejects forbidden elements', function (string $inner) {
    [$out, $codes] = runSvg(svg($inner));
    expect($out)->toBeNull()->and($codes)->toContain('svg_forbidden_element');
})->with([
    'script' => ['<script>alert(1)</script>'],
    'script in g' => ['<g><script type="text/javascript">alert(1)</script></g>'],
    'foreignObject' => ['<foreignObject width="10" height="10"><div xmlns="http://www.w3.org/1999/xhtml"><img src="x" onerror="alert(1)"/></div></foreignObject>'],
    'style element' => ['<style>@import url(http://evil/x.css);</style>'],
    'image' => ['<image href="http://evil/x.png"/>'],
    'a link' => ['<a href="javascript:alert(1)"><rect width="1" height="1"/></a>'],
    'animate' => ['<rect width="1" height="1"><animate attributeName="width" to="9"/></rect>'],
    'set javascript' => ['<a><set attributeName="href" to="javascript:alert(1)"/></a>'],
    'text' => ['<text x="1" y="1">hi</text>'],
    'iframe in html ns' => ['<iframe xmlns="http://www.w3.org/1999/xhtml" src="http://evil"/>'],
    'html namespace element' => ['<body xmlns="http://www.w3.org/1999/xhtml" onload="alert(1)"/>'],
    'mixed case script' => ['<ScRiPt>alert(1)</ScRiPt>'],
    'feImage' => ['<filter id="f"><feImage href="http://evil/x"/></filter>'],
    'switch' => ['<switch><g/></switch>'],
    'handler' => ['<handler>alert(1)</handler>'],
    'unknown element' => ['<blink/>'],
]);

it('rejects forbidden attributes', function (string $attrs, string $code) {
    [$out, $codes] = runSvg(svg('<rect width="1" height="1" ' . $attrs . '/>'));
    expect($out)->toBeNull()->and($codes)->toContain($code);
})->with([
    'onload' => ['onload="alert(1)"', 'svg_forbidden_attribute'],
    'onclick' => ['onclick="alert(1)"', 'svg_forbidden_attribute'],
    'onmouseover uppercase' => ['OnMouseOver="alert(1)"', 'svg_forbidden_attribute'],
    'style' => ['style="fill:url(http://evil/x)"', 'svg_forbidden_attribute'],
    'class' => ['class="x"', 'svg_forbidden_attribute'],
    'xlink:href js' => ['xmlns:xlink="http://www.w3.org/1999/xlink" xlink:href="javascript:alert(1)"', 'svg_forbidden_attribute'],
    'href on rect' => ['href="http://evil/x"', 'svg_forbidden_attribute'],
    'fill external url' => ['fill="url(http://evil/x.svg#a)"', 'svg_external_ref'],
    'fill data url' => ['fill="url(data:image/svg+xml;base64,AAAA)"', 'svg_external_ref'],
    'fill javascript' => ['fill="javascript:alert(1)"', 'svg_external_ref'],
    'fill unknown local id' => ['fill="url(#nope)"', 'svg_forbidden_attribute'],
    'filter external' => ['filter="url(https://evil/f.svg#x)"', 'svg_external_ref'],
    'mask external' => ['mask="url(//evil/x#m)"', 'svg_external_ref'],
    'clip-path external' => ['clip-path="url(http://evil/c#c)"', 'svg_external_ref'],
    'unknown attribute' => ['data-x="1"', 'svg_forbidden_attribute'],
    'control char (not even well-formed XML)' => ["fill=\"red\x01\"", 'svg_invalid'],
    'tabindex' => ['tabindex="0"', 'svg_forbidden_attribute'],
]);

it('rejects use with an external or unknown target', function (string $href) {
    [$out, $codes] = runSvg(svg('<use href="' . $href . '"/>'));
    expect($out)->toBeNull()->and($codes)->not->toBe([]);
})->with(['http://evil/x.svg#a', '//evil/x.svg#a', 'data:image/svg+xml;base64,AAAA', 'other.svg#a', '#missing', 'javascript:alert(1)']);

it('rejects XXE, DOCTYPE and entity tricks before parsing them', function (string $payload) {
    [$out, $codes] = runSvg($payload);
    expect($out)->toBeNull()->and($codes)->toContain('svg_doctype');
})->with([
    'XXE file' => ['<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><title>&xxe;</title></svg>'],
    'XXE http' => ['<!DOCTYPE svg [<!ENTITY % r SYSTEM "http://evil/x.dtd"> %r;]><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"/>'],
    'billion laughs' => ['<?xml version="1.0"?><!DOCTYPE lolz [<!ENTITY lol "lol"><!ENTITY lol2 "&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;"><!ENTITY lol3 "&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;">]><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><title>&lol3;</title></svg>'],
    'doctype lowercase' => ['<!doctype svg><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"/>'],
    'doctype with space' => ['<!  DOCTYPE svg><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"/>'],
    'external dtd' => ['<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"/>'],
]);

it('does not expand the use graph (billion laughs through references)', function () {
    $defs = '<g id="a0"><rect width="1" height="1"/></g>';
    for ($i = 1; $i <= 6; $i++) {
        $defs .= '<g id="a' . $i . '">' . str_repeat('<use href="#a' . ($i - 1) . '"/>', 10) . '</g>';
    }
    [$out, $codes] = runSvg(svg($defs . '<use href="#a6"/>'));
    expect($out)->toBeNull()->and($codes)->toContain('svg_invalid');
});

it('rejects circular use references', function () {
    [$out, $codes] = runSvg(svg('<g id="a"><use href="#b"/></g><g id="b"><use href="#a"/></g><use href="#a"/>'));
    expect($out)->toBeNull()->and($codes)->toContain('svg_invalid');
});

it('rejects malformed, non svg and oversized documents', function (string $source) {
    [$out, $codes] = runSvg($source);
    expect($out)->toBeNull()->and($codes)->not->toBe([]);
})->with([
    'not xml' => ['hello'],
    'unclosed' => ['<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><g>'],
    'wrong root' => ['<html xmlns="http://www.w3.org/1999/xhtml"><body/></html>'],
    'svg without namespace' => ['<svg viewBox="0 0 1 1"><rect width="1" height="1"/></svg>'],
    'no viewBox' => ['<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>'],
    'zero viewBox' => ['<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 0 0"/>'],
    'NUL byte' => ["<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 1 1\">\0</svg>"],
    'invalid utf8' => ["<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 1 1\"><title>\xFF</title></svg>"],
    'xml-stylesheet' => ['<?xml-stylesheet href="http://evil/x.css"?><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"/>'],
    'text outside title' => ['<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1">free text</svg>'],
    'bidi override' => ["<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 1 1\"><title>a\u{202E}b</title></svg>"],
    'path with letters' => [svg('<path d="M0 0 alert(1)"/>')],
    'foreign namespace attribute' => [svg('<g xmlns:e="http://evil" e:x="1"/>')],
]);

it('limits depth and node count', function () {
    $deep = str_repeat('<g>', 40) . str_repeat('</g>', 40);
    [$out, $codes] = runSvg(svg($deep));
    expect($out)->toBeNull()->and($codes)->toContain('svg_invalid');

    $wide = str_repeat('<rect width="1" height="1"/>', 2100);
    [$out2, $codes2] = runSvg(svg($wide));
    expect($out2)->toBeNull()->and($codes2)->toContain('svg_invalid');
});

it('limits feTurbulence octaves and feDisplacementMap scale and warns about the cost', function () {
    [$out, $codes] = runSvg(svg('<filter id="f"><feTurbulence baseFrequency="0.8" numOctaves="9"/></filter>'));
    expect($out)->toBeNull();

    [$ok, , $report] = runSvg(svg('<filter id="f"><feTurbulence baseFrequency="0.8" numOctaves="3" type="fractalNoise"/><feDisplacementMap in2="x" scale="20"/></filter>'));
    expect($ok)->not->toBeNull()->and($report->warningCodes())->toContain('css_costly_property');

    [$bad] = runSvg(svg('<filter id="f"><feDisplacementMap scale="5000"/></filter>'));
    expect($bad)->toBeNull();
});

it('the rebuilt output of every accepted SVG contains no dangerous token', function () {
    [$out] = runSvg(svg('<g id="a"><path d="M0 0L9 9" fill="url(#a)" /></g><title>&lt;script&gt;alert(1)&lt;/script&gt;</title>'));
    // entity-escaped text stays inert text: the serializer re-escapes it
    expect($out)->not->toMatch('/<script|onload|javascript:|<!DOCTYPE|<!ENTITY|xlink|<style|foreignObject|href="http/i');
});

it('is a fixed point: sanitizing the output returns the same bytes', function () {
    [$out] = runSvg(svg('<defs><radialGradient id="g" cx="0.5"><stop offset="0" stop-color="#fff"/></radialGradient></defs><circle cx="5" cy="5" r="4" fill="url(#g)" opacity=".5"/>'));
    [$again] = runSvg($out);
    expect($again)->toBe($out);
});
