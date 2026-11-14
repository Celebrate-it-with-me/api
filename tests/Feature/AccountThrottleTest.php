<?php

use App\Mail\ResetPasswordEmail;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    RateLimiter::clear('x');
});

it('locks the login of one account after 5 failures even with the right password afterwards (S-11)', function () {
    $user = User::factory()->create(['password' => 'OriginalPass1', 'email_verified_at' => now()]);
    $bad = ['email' => $user->email, 'password' => 'wrong', 'device' => 'x'];

    foreach (range(1, 5) as $i) {
        $this->postJson('/api/v1/app/login', $bad)->assertStatus(401);
    }
    $this->postJson('/api/v1/app/login', $bad)->assertStatus(429);
    $this->postJson('/api/v1/app/login', ['password' => 'OriginalPass1'] + $bad)->assertStatus(429);

    // Another account from the same address is not locked.
    $other = User::factory()->create(['password' => 'OtherPass12345', 'email_verified_at' => now()]);
    $this->postJson('/api/v1/app/login', ['email' => $other->email, 'password' => 'OtherPass12345', 'device' => 'x'])->assertOk();
});

it('clears the failures after a good login', function () {
    $user = User::factory()->create(['password' => 'OriginalPass1', 'email_verified_at' => now()]);

    foreach (range(1, 4) as $i) {
        $this->postJson('/api/v1/app/login', ['email' => $user->email, 'password' => 'wrong', 'device' => 'x'])->assertStatus(401);
    }
    $this->postJson('/api/v1/app/login', ['email' => $user->email, 'password' => 'OriginalPass1', 'device' => 'x'])->assertOk();
    $this->postJson('/api/v1/app/login', ['email' => $user->email, 'password' => 'wrong', 'device' => 'x'])->assertStatus(401);
});

it('sends at most 3 reset emails per hour to one address, answering the same (S-11)', function () {
    Mail::fake();
    $user = User::factory()->create();

    foreach (range(1, 6) as $i) {
        $this->postJson('/api/v1/app/forgot-password', ['email' => $user->email])->assertOk();
    }

    Mail::assertSent(ResetPasswordEmail::class, 3);
});

it('limits wrong current-password guesses and closes the other tokens on change (S-23)', function () {
    $user = User::factory()->create(['password' => 'OriginalPass1']);
    $user->createToken('other-device');
    $current = $user->createToken('this-device')->plainTextToken;
    $payload = ['newPassword' => 'BrandNew12345', 'newPasswordConfirmation' => 'BrandNew12345'];

    $this->withToken($current)->postJson('/api/v1/app/user/update-password', ['currentPassword' => 'OriginalPass1'] + $payload)->assertOk();

    expect(Hash::check('BrandNew12345', $user->fresh()->password))->toBeTrue()
        ->and($user->tokens()->count())->toBe(1);
});

it('stops guessing the current password after 5 tries', function () {
    $user = User::factory()->create(['password' => 'OriginalPass1']);
    $payload = ['newPassword' => 'BrandNew12345', 'newPasswordConfirmation' => 'BrandNew12345'];

    foreach (range(1, 5) as $i) {
        $this->actingAs($user)->postJson('/api/v1/app/user/update-password', ['currentPassword' => 'nope'] + $payload)->assertStatus(422);
    }
    $this->actingAs($user)->postJson('/api/v1/app/user/update-password', ['currentPassword' => 'OriginalPass1'] + $payload)->assertStatus(429);
});

it('exposes Retry-After (and Content-Disposition) to the browser on a 429 login', function () {
    config(['cors.allowed_origins' => ['http://dev.app.cwm.com']]);
    $user = User::factory()->create(['password' => 'OriginalPass1', 'email_verified_at' => now()]);
    $headers = ['Origin' => 'http://dev.app.cwm.com'];

    foreach (range(1, 5) as $i) {
        $this->withHeaders($headers)->postJson('/api/v1/app/login', ['email' => $user->email, 'password' => 'wrong', 'device' => 'x'])->assertStatus(401);
    }
    $res = $this->withHeaders($headers)->postJson('/api/v1/app/login', ['email' => $user->email, 'password' => 'wrong', 'device' => 'x'])->assertStatus(429);

    expect($res->headers->get('Retry-After'))->not->toBeNull();
    $exposed = strtolower((string) $res->headers->get('Access-Control-Expose-Headers'));
    expect($exposed)->toContain('retry-after')->and($exposed)->toContain('content-disposition');
});
