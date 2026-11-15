<?php

use App\Support\TemplatePackage\CssSanitizer;
use App\Support\TemplatePackage\Report;
use App\Support\TemplatePackage\SvgSanitizer;
use Tests\Unit\TemplatePackage\PackageBuilder;

afterEach(fn () => PackageBuilder::cleanup());

it('accepts the good fixture package and returns sanitized artifacts', function () {
    $result = PackageBuilder::good()->validate();

    expect($result['report']['errors'])->toBe([])
        ->and($result['valid'])->toBeTrue()
        ->and($result['report']['manifest']['slug'])->toBe('botanical')
        ->and($result['artifacts']['css'])->toContain('[data-inv-theme="botanical"]')
        ->and($result['artifacts']['css'])->toContain('@keyframes botanical-pkg-drift')
        ->and($result['artifacts']['css'])->toContain('animation:botanical-pkg-drift')
        ->and(array_keys($result['artifacts']['ast']))->toEqualCanonicalizing(['layout.html', 'sections/cover.html', 'sections/rsvp.html', 'sections/closing.html'])
        ->and($result['artifacts']['svg'])->toHaveKey('assets/img/wash.svg')
        ->and($result['report']['stats'])->toHaveKeys(['files', 'runtimeBytes', 'elapsedMs']);
});

it('produces an AST whose nodes only use the closed schema', function () {
    $result = PackageBuilder::good()->validate();
    $allowed = ['el', 'text', 'var', 'q', 'if'];
    $walk = function ($node) use (&$walk, $allowed) {
        expect($allowed)->toContain($node['t']);
        if ($node['t'] === 'el') {
            expect(array_keys((array) $node))->toBe(['t', 'tag', 'attrs', 'children']);
            array_map($walk, $node['children']);
        }
        if ($node['t'] === 'if') {
            expect(array_keys((array) $node))->toBe(['t', 'path', 'children']);
            array_map($walk, $node['children']);
        }
        if ($node['t'] === 'q') {
            expect($node['children'])->toBe([]);
        }
    };
    foreach ($result['artifacts']['ast'] as $ast) {
        expect($ast['v'])->toBe(1);
        array_map($walk, $ast['nodes']);
    }
});

it('sanitizing is idempotent: the sanitized CSS and SVG pass again unchanged', function () {
    $first = PackageBuilder::good()->validate();
    $css = $first['artifacts']['css'];
    $svg = $first['artifacts']['svg']['assets/img/wash.svg'];

    // The sanitized SVG is a fixed point.
    $again = (new SvgSanitizer)->sanitize($svg, 'x.svg', new Report);
    expect($again)->toBe($svg);

    // Sanitizing the sanitized CSS gives exactly the same text (fixed point).
    $r = new Report;
    $second = (new CssSanitizer)->sanitize($css, 'botanical', 'theme.css', $r, ['assets/img/paper.webp', 'assets/img/wash.svg'], ['Great Vibes']);
    expect($r->toArray()['errors'])->toBe([])->and($second)->toBe($css);
});

it('reports a version that is not greater than the installed one', function () {
    $result = PackageBuilder::good()->validate(['botanical' => '1.0.0']);
    expect(PackageBuilder::errors($result))->toBe(['version_not_greater']);
});
