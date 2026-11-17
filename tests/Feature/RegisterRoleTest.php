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
