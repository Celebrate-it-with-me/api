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

// W6-02 --------------------------------------------------------------------------------------------------------------

use App\Support\TemplatePackage\CssSanitizer;
use App\Support\TemplatePackage\Report;

/** @return array{0: ?string, 1: list<string>} */
function w6css(string $source, array $kinds = ['pkg-wrapper' => 'wrapper', 'pkg-photo' => 'photo']): array
{
    $report = new Report;
    $out = (new CssSanitizer)->sanitize($source, 'botanical', 'theme.css', $report, ['assets/img/a.webp'], ['Great Vibes'], $kinds);

    return [$out, $report->errorCodes()];
}

it('W6-02 refuses the ways of hiding or moving what the page root, generic selectors and wrappers contain', function (string $css) {
    [$out, $codes] = w6css($css);
    expect($out)->toBeNull()->and($codes)->not->toBe([]);
})->with([
    // 1. the root
    'root display none' => ['.inv-root{display:none}'],
    'body opacity 0' => ['body{opacity:0}'],
    'root pointer-events' => [':root{pointer-events:none}'],
    'html display none' => ['html{display:none}'],
    'root scale 0' => ['.inv-root{transform:scale(0)}'],
    'root filter' => ['.inv-root{filter:opacity(0)}'],
    'root clip-path' => ['.inv-root{clip-path:inset(100%)}'],
    'root height' => ['body{height:0;overflow:hidden}'],
    'root position' => ['body{position:absolute;left:-99999px}'],
    // 2. generic selectors
    'div width 0' => ['div{width:0}'],
    'star height 0' => ['*{height:0}'],
    'p font-size 0' => ['p{font-size:0}'],
    'star transparent text' => ['*{color:transparent}'],
    'child of class margin' => ['.pkg-a > *{margin-left:-99999px}'],
    'is() reaching a system hook' => ['.pkg-a:is(.inv-q--rsvp){display:none}'],
    // 3. keyframes applied to a wrapper
    'keyframes opacity on wrapper' => ['@keyframes h{to{opacity:0;pointer-events:none}} .pkg-wrapper{animation:h 1s forwards}'],
    'keyframes display on wrapper' => ['@keyframes h{to{display:none}} .pkg-wrapper{animation-name:h}'],
    'keyframes transform on root' => ['@keyframes h{to{transform:translateX(-99999px)}} body{animation:h 1s}'],
    'keyframes on generic selector' => ['@keyframes h{to{opacity:0}} div{animation:h 1s}'],
    // 4. disguised zeros
    'opacity percent' => ['.pkg-wrapper{opacity:0%}'],
    'opacity calc' => ['.pkg-wrapper{opacity:calc(0)}'],
    'opacity var' => ['.pkg-wrapper{opacity:var(--pkg-o)}'],
    'width calc' => ['.pkg-wrapper{width:calc(0px)}'],
    'width tiny' => ['.pkg-wrapper{width:.01px;overflow:hidden}'],
    'font-size calc' => ['.pkg-wrapper{font-size:calc(0px)}'],
    'font-size tiny em' => ['.pkg-wrapper{font-size:.01em}'],
    'font-size clamp of tiny' => ['.pkg-wrapper{font-size:clamp(1px,1px,1px)}'],
    'min() with a tiny arg' => ['.pkg-wrapper{width:min(100%,1px)}'],
    // 5. moving the block off screen
    'translateX' => ['.pkg-wrapper{transform:translateX(-99999px)}'],
    'translate' => ['.pkg-wrapper{translate:-99999px 0}'],
    'scale 0' => ['.pkg-wrapper{scale:0}'],
    'rotate' => ['.pkg-wrapper{rotate:90deg}'],
    'absolute left' => ['.pkg-wrapper{position:absolute;left:-99999px}'],
    'negative margin' => ['.pkg-wrapper{margin-left:-99999px}'],
    'negative margin shorthand' => ['.pkg-wrapper{margin:0 0 0 -400px}'],
    'huge margin' => ['.pkg-wrapper{margin-left:3999px}'],
    'height 1px' => ['.pkg-wrapper{height:1px;overflow:hidden}'],
    'max-height 1px' => ['.pkg-wrapper{max-height:1px}'],
    'text-indent' => ['.pkg-wrapper{text-indent:-9999px}'],
    'line-height 0' => ['.pkg-wrapper{line-height:0;overflow:hidden}'],
    'system hook margin' => ['[data-inv-section="rsvp"]{margin-left:-99999px}'],
    'system hook transform' => ['.inv-q--rsvp{transform:translateY(-5000px)}'],
    // 6. invisibility by color or by cover
    'transparent text' => ['.inv-q--cta{color:transparent}'],
    'hsla transparent' => ['.inv-q--cta{color:hsla(0,0%,0%,0)}'],
    'rgb slash alpha' => ['.inv-q--cta{color:rgb(0 0 0 / 0)}'],
    'hex alpha' => ['.inv-q--cta{color:#00000000}'],
    'color-mix' => ['.pkg-wrapper{color:color-mix(in srgb,red,transparent)}'],
    'pkg var text color' => ['.pkg-wrapper{color:var(--pkg-ink)}'],
    'text same as background' => ['.pkg-wrapper{color:#fff;background-color:#fff}'],
    'giant spread shadow' => ['.inv-q--cta{box-shadow:0 0 0 99999px #fff}'],
    'giant outline' => ['.inv-q--cta{outline:99999px solid #fff}'],
    'giant letter spacing' => ['.pkg-wrapper{letter-spacing:300px}'],
]);

it('W6-02 keeps what a real design needs', function (string $css) {
    [$out, $codes] = w6css($css);
    expect($codes)->toBe([])->and($out)->not->toBeNull();
})->with([
    'body color and font' => ['body{background:var(--inv-surface);color:var(--inv-ink);font-family:"Great Vibes",cursive;line-height:1.5}'],
    'root decoration' => ['body::before{content:"";position:absolute;inset:0;z-index:1;opacity:0;pointer-events:none;background:url(assets/img/a.webp)}'],
    'heading clamp' => ['h1{font-size:clamp(2rem,8vw,3.5rem);margin:0 0 1rem}'],
    'wrapper layout' => ['.pkg-wrapper{display:flex;flex-direction:column;gap:1rem;padding:2rem 1rem;max-width:min(100%,760px);margin:0 auto;border-top:1px solid #ccc;background:#fff}'],
    'wrapper decoration' => ['.pkg-wrapper::after{content:"";position:absolute;right:-20px;top:-20px;width:80px;height:80px;opacity:.2;transform:rotate(12deg)}'],
    'free element animation' => ['@keyframes drift{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}} .pkg-deco{animation:drift 2s ease-out both}'],
    'wrapper color animation' => ['@keyframes glow{from{box-shadow:0 0 0 0 #fff}to{box-shadow:0 0 8px 2px #eee}} .pkg-wrapper{animation:glow 2s}'],
    'system hook look' => ['.inv-q--cta{background:#b8860b;color:#fff;border-radius:999px;padding:.75rem 2rem;box-shadow:0 4px 12px rgba(0,0,0,.2);letter-spacing:.05em}'],
    'photo layout' => ['.pkg-photo{width:100%;aspect-ratio:4/5;mask-image:url(assets/img/a.webp);transform:rotate(-2deg)}'],
]);
