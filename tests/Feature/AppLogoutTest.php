<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

const LO_HEADERS = ['Origin' => 'http://dev.app.cwm.com', 'Referer' => 'http://dev.app.cwm.com/'];

it('closes a cookie session instead of failing with a 500 (S-07)', function () {
    $user = User::factory()->create(['password' => 'OriginalPass1', 'email_verified_at' => now()]);

    $this->withHeaders(LO_HEADERS)->get('/sanctum/csrf-cookie');
    $this->withHeaders(LO_HEADERS)->postJson('/api/v1/app/login', ['email' => $user->email, 'password' => 'OriginalPass1', 'device' => 'x'])->assertOk();
    $this->withHeaders(LO_HEADERS)->getJson('/api/v1/app/user')->assertOk();

    $this->withHeaders(LO_HEADERS)->postJson('/api/v1/app/logout')->assertOk();

    app('auth')->forgetGuards();
    $this->withHeaders(LO_HEADERS)->getJson('/api/v1/app/user')->assertUnauthorized();
});

it('revokes the bearer token on logout', function () {
    $user = User::factory()->create();
    $plain = $user->createToken('app')->plainTextToken;

    $this->withToken($plain)->postJson('/api/v1/app/logout')->assertOk();

    expect($user->tokens()->count())->toBe(0);
});
