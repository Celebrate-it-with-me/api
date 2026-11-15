<?php

use App\Http\Middleware\TrustProxies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

it('sets the security headers on API responses (S-17)', function () {
    $res = $this->getJson('/api/v1/public/system-status');

    $res->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    expect($res->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'none'")
        ->and($res->headers->has('Strict-Transport-Security'))->toBeFalse();
});

it('adds HSTS only over https', function () {
    $this->getJson('https://dev.api.cwm.com/api/v1/public/system-status')->assertHeader('Strict-Transport-Security');
});

it('also marks error responses', function () {
    $this->getJson('/api/v1/admin/userInfo')->assertUnauthorized()->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('trusts X-Forwarded-For only from the configured proxies (I-04)', function () {
    $ip = fn (string $proxies) => (function () use ($proxies) {
        config(['app.trusted_proxies' => $proxies]);
        $request = Request::create('/x', 'GET', server: ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9']);
        (new TrustProxies)->handle($request, fn ($r) => response('ok'));

        return $request->ip();
    })();

    expect($ip(''))->toBe('10.0.0.5')
        ->and($ip('10.0.0.5'))->toBe('203.0.113.9')
        ->and($ip('10.0.0.0/8'))->toBe('203.0.113.9')
        ->and($ip('192.168.1.1'))->toBe('10.0.0.5');

    Request::setTrustedProxies([], 0); // static state must not leak into other tests
});
