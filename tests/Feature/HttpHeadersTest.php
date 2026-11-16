<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

describe('compression', function () {
    function bigJson(): array
    {
        return ['data' => str_repeat('quinvite ', 600)];
    }

    beforeEach(function () {
        Route::middleware('api')->get('/api/v1/_perf/big', fn () => response()->json(bigJson()));
        Route::middleware('api')->get('/api/v1/_perf/small', fn () => response()->json(['ok' => true]));
    });

    it('gzips a big JSON answer when the client accepts it, and says it varies', function () {
        $plain = $this->getJson('/api/v1/_perf/big');
        $gz = $this->withHeaders(['Accept-Encoding' => 'gzip, deflate, br'])->getJson('/api/v1/_perf/big');

        expect($gz->headers->get('Content-Encoding'))->toBe('gzip')
            ->and(gzdecode($gz->getContent()))->toBe($plain->getContent())
            ->and(strlen($gz->getContent()))->toBeLessThan(strlen($plain->getContent()) / 5)
            ->and($gz->headers->get('Content-Length'))->toBe((string) strlen($gz->getContent()))
            ->and(implode(',', $gz->headers->all('vary')))->toContain('Accept-Encoding')
            ->and($plain->headers->has('Content-Encoding'))->toBeFalse();
    });

    it('leaves small answers, gzip;q=0 and a disabled switch alone', function () {
        expect($this->withHeaders(['Accept-Encoding' => 'gzip'])->getJson('/api/v1/_perf/small')->headers->has('Content-Encoding'))->toBeFalse();
        expect($this->withHeaders(['Accept-Encoding' => 'gzip;q=0'])->getJson('/api/v1/_perf/big')->headers->has('Content-Encoding'))->toBeFalse();

        config(['http.compression.enabled' => false]);
        expect($this->withHeaders(['Accept-Encoding' => 'gzip'])->getJson('/api/v1/_perf/big')->headers->has('Content-Encoding'))->toBeFalse();
    });
});

describe('cache control of the API', function () {
    beforeEach(function () {
        Route::middleware('api')->get('/api/v1/_perf/explicit', fn () => response()->json(['ok' => true])->header('Cache-Control', 'public, max-age=60'));
        Route::middleware('api')->get('/api/v1/_perf/default', fn () => response()->json(['ok' => true]));
    });

    it('forbids storing JSON answers unless a route says otherwise', function () {
        expect($this->getJson('/api/v1/_perf/default')->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
        expect($this->getJson('/api/v1/_perf/explicit')->headers->get('Cache-Control'))->toContain('public');
        expect($this->getJson('/api/v1/public/system-status')->headers->get('Cache-Control'))->toContain('no-store');
    });

    it('never stores an authenticated answer', function () {
        $user = User::factory()->create();

        $res = $this->actingAs($user, 'sanctum')->getJson('/api/v1/app/user/hydrate/' . $user->id)->assertOk();

        expect($res->headers->get('Cache-Control'))->toContain('no-store');
    });
});

describe('GET /up', function () {
    it('answers 200 with app and database checks, without session or throttle', function () {
        foreach (range(1, 80) as $_) {
            $res = $this->getJson('/up');
        }

        $res->assertOk()->assertJson(['status' => 'ok', 'checks' => ['app' => 'ok', 'database' => 'ok', 'redis' => 'skipped']])
            ->assertHeader('Cache-Control', 'no-store, private');
        expect($res->headers->has('Set-Cookie'))->toBeFalse()->and($res->headers->has('X-RateLimit-Limit'))->toBeFalse();
    });

    it('answers 503 without leaking the reason when the database is down', function () {
        DB::shouldReceive('select')->andThrow(new RuntimeException('secret dsn'));

        $res = $this->getJson('/up')->assertStatus(503);

        expect($res->json('status'))->toBe('fail')->and($res->getContent())->not->toContain('secret');
    });
});
