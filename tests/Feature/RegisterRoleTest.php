<?php

use App\Models\Events;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

it('registers a new account with the appUser role and not the owner one', function () {
    $this->postJson('/api/v1/app/register', [
        'name' => 'Nueva Usuaria', 'email' => 'nueva@cwm.test', 'password' => 'Password123',
    ])->assertCreated();

    $user = User::where('email', 'nueva@cwm.test')->firstOrFail();

    expect($user->hasRole('appUser'))->toBeTrue()
        ->and($user->hasRole('owner'))->toBeFalse()
        ->and($user->getAllPermissions()->pluck('name')->all())->toBe(['app']);
});

it('gives a freshly registered account a 404 on the event of someone else', function () {
    $this->postJson('/api/v1/app/register', [
        'name' => 'Nueva Usuaria', 'email' => 'nueva@cwm.test', 'password' => 'Password123',
    ])->assertCreated();
    $new = User::where('email', 'nueva@cwm.test')->firstOrFail();
    $foreign = Events::factory()->create();

    $this->actingAs($new)->getJson("/api/v1/app/event/{$foreign->id}")->assertNotFound();
});

it('gives appUser to an account created by social sign-in, and leaves an existing one alone', function () {
    $socialUser = fn (string $id, string $email) => tap(Mockery::mock(Laravel\Socialite\Two\User::class), function ($m) use ($id, $email) {
        $m->shouldReceive('getId')->andReturn($id);
        $m->shouldReceive('getEmail')->andReturn($email);
        $m->shouldReceive('getName')->andReturn('Social User');
        $m->shouldReceive('getNickname')->andReturn(null);
        $m->shouldReceive('getAvatar')->andReturn(null);
        $m->token = 't';
        $m->refreshToken = null;
        $m->expiresIn = null;
    });
    $existing = User::factory()->create(['email' => 'vieja@cwm.test']);

    foreach (['g-new' => 'social@cwm.test', 'g-old' => 'vieja@cwm.test'] as $id => $email) {
        Laravel\Socialite\Facades\Socialite::shouldReceive('driver->user')->once()->andReturn($socialUser($id, $email));
        app(App\Http\Services\Auth\SocialAuthService::class)->callback('google');
    }

    expect(User::where('email', 'social@cwm.test')->firstOrFail()->hasRole('appUser'))->toBeTrue()
        ->and($existing->fresh()->getRoleNames()->all())->toBe([]);
});
