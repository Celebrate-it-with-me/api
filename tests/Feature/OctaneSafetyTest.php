<?php

use App\Support\Mail\MessageOverrides;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

it('gives every request (or queue job) a fresh MessageOverrides, so a preview cannot leak into the next one', function () {
    $overrides = app(MessageOverrides::class);

    try {
        $overrides->withPreview('confirm-account', 'es', ['subject' => 'Texto sin guardar'], function () {
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
    }
    // A preview that is still on when the request ends must not reach the next one.
    (fn () => $this->preview = ['es|mail.reset.subject' => 'sticky'])->call($overrides);

    app()->forgetScopedInstances();

    $next = app(MessageOverrides::class);
    expect($next)->not->toBe($overrides)
        ->and((fn () => $this->preview)->call($next))->toBeNull();
});

it('answers a clear 503 when Redis is down in development', function () {
    Route::middleware('api')->get('/api/v1/_perf/redis-down', fn () => throw new RuntimeException('wrapped', 0, new RedisException('Connection refused')));

    $res = $this->getJson('/api/v1/_perf/redis-down')->assertStatus(503);

    expect($res->json('code'))->toBe('redis_unavailable')->and($res->json('message'))->toContain('cwm_redis');
});
