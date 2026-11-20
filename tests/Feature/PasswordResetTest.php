<?php

use App\Mail\ResetPasswordEmail;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

it('sends a reset email whose link is signed and points at the check route', function () {
    Mail::fake();
    $user = User::factory()->create();

    $this->postJson('/api/v1/app/forgot-password', ['email' => $user->email])->assertOk();

    Mail::assertSent(ResetPasswordEmail::class, function (ResetPasswordEmail $mail) use ($user) {
        parse_str(parse_url(urldecode(explode('confirm=', $mail->resetPasswordLink)[1]), PHP_URL_QUERY), $query);

        return $mail->hasTo($user->email)
            && str_contains($mail->resetPasswordLink, 'check-password-link')
            && isset($query['signature'], $query['expires']);
    });
});

it('lets staff send the same reset email from the admin', function () {
    Mail::fake();
    $staff = User::factory()->create();
    $staff->assignRole('support');
    $user = User::factory()->create();

    $this->actingAs($staff)->postJson("/api/v1/admin/users/{$user->id}/password-reset", ['reason' => 'User locked out'])->assertOk();

    Mail::assertSent(ResetPasswordEmail::class, fn ($mail) => $mail->hasTo($user->email));
});

it('does not say whether an email exists', function () {
    Mail::fake();

    $this->postJson('/api/v1/app/forgot-password', ['email' => 'nobody@example.com'])->assertOk();

    Mail::assertNothingSent();
});

it('refuses to reset a password without a valid token (S-01)', function () {
    $user = User::factory()->create(['password' => 'OriginalPass1']);
    $body = ['email' => $user->email, 'password' => 'Hacked1234', 'password_confirmation' => 'Hacked1234'];

    $this->postJson('/api/v1/app/reset-password', $body)->assertStatus(422);
    $this->postJson('/api/v1/app/reset-password', $body + ['token' => 'forged'])
        ->assertStatus(422)->assertJsonPath('code', 'invalid_reset_token');

    expect(Hash::check('OriginalPass1', $user->fresh()->password))->toBeTrue();
});

it('answers the same way for an unknown email and a wrong token', function () {
    $user = User::factory()->create();
    $token = Password::broker()->createToken($user);
    $body = ['password' => 'Hacked1234', 'password_confirmation' => 'Hacked1234', 'token' => $token];

    $a = $this->postJson('/api/v1/app/reset-password', $body + ['email' => 'nobody@example.com']);
    $b = $this->postJson('/api/v1/app/reset-password', ['email' => $user->email, 'token' => 'bad'] + $body);

    expect($a->status())->toBe(422)->and($b->status())->toBe(422)->and($a->json())->toBe($b->json());
});

it('resets with the emailed token once, revokes api tokens and burns the link', function () {
    Mail::fake();
    $user = User::factory()->create(['password' => 'OriginalPass1']);
    $user->createToken('old');

    $this->postJson('/api/v1/app/forgot-password', ['email' => $user->email])->assertOk();

    $link = null;
    Mail::assertSent(ResetPasswordEmail::class, function ($mail) use (&$link) {
        $link = urldecode(explode('confirm=', $mail->resetPasswordLink)[1]);

        return true;
    });

    $check = $this->postJson($link)->assertOk()->assertJsonPath('data.email', $user->email);
    $check->assertJsonMissingPath('data.password');
    $token = $check->json('token');
    expect($token)->not->toBeEmpty();

    $body = ['email' => $user->email, 'token' => $token, 'password' => 'BrandNew12345', 'password_confirmation' => 'BrandNew12345'];
    $this->postJson('/api/v1/app/reset-password', $body)->assertOk();

    expect(Hash::check('BrandNew12345', $user->fresh()->password))->toBeTrue()
        ->and($user->tokens()->count())->toBe(0);

    // Single use.
    $this->postJson('/api/v1/app/reset-password', ['password' => 'Another12345', 'password_confirmation' => 'Another12345'] + $body)
        ->assertStatus(422);
});

it('rejects an expired reset token', function () {
    $user = User::factory()->create();
    $token = Password::broker()->createToken($user);

    $this->travel(2)->hours();

    $this->postJson('/api/v1/app/reset-password', [
        'email' => $user->email, 'token' => $token, 'password' => 'BrandNew12345', 'password_confirmation' => 'BrandNew12345',
    ])->assertStatus(422);
});
