<?php

use App\Providers\RouteServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    RateLimiter::clear('status|ip:127.0.0.1');
});

it('answers true without session cookies and never caches it in the browser', function () {
    $response = $this->get('/status')->assertOk();

    expect($response->json())->toBeTrue()
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->getCookies())->toBe([]);
});

it('serves the second call of a window from the cache without touching the database (R1-I-3)', function () {
    $this->get('/status')->assertOk();
    expect(Cache::has('status.database'))->toBeTrue();

    DB::shouldReceive('connection')->never();

    expect($this->get('/status')->getContent())->toBe('true');
});

it('is throttled', function () {
    $limit = RouteServiceProvider::ROUTE_LIMITS['status'][0];
    for ($i = 0; $i < $limit; $i++) {
        $this->get('/status')->assertOk();
    }

    $this->get('/status')->assertStatus(429);
});

it('reports false when the database does not answer, and still answers if the cache store is down', function () {
    DB::shouldReceive('connection')->andThrow(new PDOException('connection refused'));
    expect($this->get('/status')->getContent())->toBe('false');

    Cache::flush();
    Cache::shouldReceive('remember')->andThrow(new RuntimeException('redis down'));
    expect($this->get('/status')->getContent())->toBe('false');
});
