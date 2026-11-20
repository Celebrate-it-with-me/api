<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

function bearerToken(User $user, ?string $client): string
{
    $token = $user->createToken('t');
    $token->accessToken->forceFill(['client' => $client])->save();

    return $token->plainTextToken;
}

it('does not accept a token of the admin panel on the app routes', function () {
    $user = User::factory()->create();

    $this->withToken(bearerToken($user, 'admin'))->getJson('/api/v1/app/user')
        ->assertUnauthorized()->assertJsonPath('code', 'admin_token_not_accepted');
    $this->withToken(bearerToken($user, 'admin'))->getJson('/api/v1/app/events')->assertUnauthorized();
});

it('still accepts ordinary and unlabelled tokens on the app routes', function () {
    $user = User::factory()->create();

    $this->withToken(bearerToken($user, null))->getJson('/api/v1/app/user')->assertOk();
    $this->withToken(bearerToken($user, 'app'))->getJson('/api/v1/app/user')->assertOk();
});

it('keeps the admin token working on the panel', function () {
    $staff = User::factory()->create();
    $staff->assignRole('superadmin');

    $this->withToken(bearerToken($staff, 'admin'))->getJson('/api/v1/admin/userInfo')->assertSuccessful();
});
