<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

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
