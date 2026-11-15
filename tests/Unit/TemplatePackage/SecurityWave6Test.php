<?php

use App\Support\TemplatePackage\UrlGuard;
use Tests\Unit\TemplatePackage\PackageBuilder;

/**
 * Adversarial tests of the wave 6 audit (docs/plans/security-audit-wave6-2026-11-15.md). Each block reproduces the
 * attack of the report and checks the rejection.
 */
afterEach(fn () => PackageBuilder::cleanup());

// W6-01 / W6-16 ------------------------------------------------------------------------------------------------------

it('W6-01 rejects non-https and internal URLs in the manifest', function (string $url) {
    $r = PackageBuilder::good()->json('manifest.json', function (array $m) use ($url) {
        $m['assetsCredits'][0]['url'] = str_replace('XLONG', str_repeat('a', 400), $url);

        return $m;
    })->validate();
    expect($r['valid'])->toBeFalse()->and(PackageBuilder::errors($r))->toContain('manifest_invalid');
})->with([
    'javascript' => 'javascript:alert(document.cookie)',
    'data' => 'data:text/html;base64,PHNjcmlwdD4=',
    'http' => 'http://example.com/',
    'protocol relative' => '//evil.example/x',
    'localhost' => 'https://localhost/admin',
    'subdomain of localhost' => 'https://a.localhost/',
    'ipv4' => 'https://127.0.0.1:8080/x',
    'ipv4 decimal' => 'https://2130706433/',
    'ipv4 hex' => 'https://0x7f.0.0.1/',
    'ipv6' => 'https://[::1]/',
    'internal tld' => 'https://printer.local/',
    'single label' => 'https://intranet/',
    'credentials' => 'https://google.com@evil.example/',
    'whitespace' => 'https://evil.example/a b',
    'newline' => "https://evil.example/\njavascript:1",
    'backslash' => 'https://evil.example\\@good.example/',
    'too long' => 'https://evil.example/' . 'XLONG',
]);

it('W6-01 rejects a hostile URL in author and thirdPartyMarks too, and accepts a public https one', function () {
    $r = PackageBuilder::good()->json('manifest.json', fn ($m) => [...$m, 'author' => ['name' => 'x', 'url' => 'https://127.0.0.1/']])->validate();
    expect(PackageBuilder::errors($r))->toContain('manifest_invalid');

    $r = PackageBuilder::good()->json('manifest.json', fn ($m) => [...$m, 'visibility' => 'private', 'thirdPartyMarks' => [['name' => 'A', 'owner' => 'B', 'usage' => 'c', 'url' => 'javascript:1']]])->validate();
    expect(PackageBuilder::errors($r))->toContain('manifest_invalid');

    $r = PackageBuilder::good()->json('manifest.json', function (array $m) {
        $m['author'] = ['name' => 'x', 'url' => 'https://studio.example.com/work?a=1'];
        $m['assetsCredits'][0]['url'] = 'https://fonts.example.org/x';

        return $m;
    })->validate();
    expect($r['valid'])->toBeTrue();
});

it('W6-16 refuses external links to localhost and IP literals in HTML', function (string $href) {
    $b = PackageBuilder::good();
    $b->with('sections/closing.html', '<div class="pkg-c"><a href="' . $href . '">x</a></div>');
    $r = $b->validate();
    expect(PackageBuilder::errors($r))->toContain('html_url_forbidden');
})->with(['https://localhost/x', 'https://127.0.0.1:8080/x', 'https://10.0.0.5/', 'https://router.local/']);

it('UrlGuard unit cases', function () {
    expect(UrlGuard::isHttps('https://a.example.com:8443/p?q=1#f'))->toBeTrue()
        ->and(UrlGuard::isHttps('https://xn--bcher-kva.example/'))->toBeTrue()
        ->and(UrlGuard::isHttps(str_repeat('a', 400)))->toBeFalse()
        ->and(UrlGuard::isHttps('https://a.example:99999/'))->toBeFalse()
        ->and(UrlGuard::isHttps(['x']))->toBeFalse();
});
