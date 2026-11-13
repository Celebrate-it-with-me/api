<?php

use App\Mail\ResetPasswordEmail;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

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

    $this->actingAs($staff)->postJson("/api/v1/admin/users/{$user->id}/password-reset")->assertOk();

    Mail::assertSent(ResetPasswordEmail::class, fn ($mail) => $mail->hasTo($user->email));
});

it('does not say whether an email exists', function () {
    Mail::fake();

    $this->postJson('/api/v1/app/forgot-password', ['email' => 'nobody@example.com'])->assertOk();

    Mail::assertNothingSent();
});
