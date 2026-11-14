<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

it('has its own enabled flag, site key and secret (S-10)', function () {
    expect(config('services.hcaptcha'))->toHaveKeys(['enabled', 'site_key', 'secret'])
        ->and(config('services.hcaptcha.enabled'))->toBeFalse();
});

it('demands a valid captcha on login when enabled, sending the secret and not the flag', function () {
    config(['services.hcaptcha.enabled' => true, 'services.hcaptcha.secret' => 'the-secret']);
    $user = User::factory()->create(['password' => 'OriginalPass1', 'email_verified_at' => now()]);
    $body = ['email' => $user->email, 'password' => 'OriginalPass1', 'device' => 'x'];

    Http::fake(['hcaptcha.com/*' => Http::sequence()->push(['success' => false])->push(['success' => true])]);
    $this->postJson('/api/v1/app/login', $body)->assertStatus(422); // no token: not even asked
    $this->postJson('/api/v1/app/login', $body + ['hcaptcha_token' => 'bad'])->assertStatus(422);

    $this->postJson('/api/v1/app/login', $body + ['hcaptcha_token' => 'good'])->assertOk();
    Http::assertSent(fn ($r) => $r['secret'] === 'the-secret');
});

it('skips the captcha when disabled', function () {
    $user = User::factory()->create(['password' => 'OriginalPass1', 'email_verified_at' => now()]);

    $this->postJson('/api/v1/app/login', ['email' => $user->email, 'password' => 'OriginalPass1', 'device' => 'x'])->assertOk();
});
