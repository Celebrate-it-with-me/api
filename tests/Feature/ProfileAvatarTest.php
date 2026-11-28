<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

const AVATAR_URL = '/api/v1/app/user/update-profile';

beforeEach(fn () => Storage::fake(config('filesystems.media_disk')));

function avatarDisk()
{
    return Storage::disk(config('filesystems.media_disk'));
}

it('uploads an avatar and returns its public url', function () {
    $user = User::factory()->create(['avatar' => null]);

    $url = test()->actingAs($user)->post(AVATAR_URL, ['name' => 'Ana', 'avatar' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])
        ->assertOk()->json('data.avatar_url');

    $user->refresh();
    avatarDisk()->assertExists($user->avatar);
    expect($url)->toBe($user->avatar_url)->and($url)->not->toBeEmpty();
});

it('deletes the previous file when the avatar is replaced', function () {
    $user = User::factory()->create();
    avatarDisk()->put('avatars/old.jpg', 'x');
    $user->forceFill(['avatar' => 'avatars/old.jpg'])->save();

    test()->actingAs($user)->post(AVATAR_URL, ['name' => 'Ana', 'avatar' => UploadedFile::fake()->image('b.jpg')], ['Accept' => 'application/json'])->assertOk();

    avatarDisk()->assertMissing('avatars/old.jpg');
    avatarDisk()->assertExists($user->refresh()->avatar);
});

it('removes the avatar with remove_avatar, as multipart or JSON, and exposes null', function (bool $json) {
    $user = User::factory()->create();
    avatarDisk()->put('avatars/me.jpg', 'x');
    $user->forceFill(['avatar' => 'avatars/me.jpg'])->save();

    $payload = ['name' => 'Ana', 'remove_avatar' => $json ? true : '1'];
    $response = $json
        ? test()->actingAs($user)->postJson(AVATAR_URL, $payload)
        : test()->actingAs($user)->post(AVATAR_URL, $payload, ['Accept' => 'application/json']);

    $response->assertOk()->assertJsonPath('data.avatar_url', null);
    avatarDisk()->assertMissing('avatars/me.jpg');
    expect($user->refresh()->avatar)->toBeNull()
        ->and(test()->actingAs($user)->getJson('/api/v1/app/user')->json('data.user.avatar_url'))->toBeNull();
})->with([false, true]);

it('keeps the avatar when the request says nothing about it', function () {
    $user = User::factory()->create();
    avatarDisk()->put('avatars/me.jpg', 'x');
    $user->forceFill(['avatar' => 'avatars/me.jpg'])->save();

    test()->actingAs($user)->postJson(AVATAR_URL, ['name' => 'Ana'])->assertOk();

    avatarDisk()->assertExists('avatars/me.jpg');
});

it('lets a new file win over remove_avatar', function () {
    $user = User::factory()->create(['avatar' => null]);

    test()->actingAs($user)->post(AVATAR_URL, ['name' => 'Ana', 'remove_avatar' => '1', 'avatar' => UploadedFile::fake()->image('c.jpg')], ['Accept' => 'application/json'])->assertOk();

    avatarDisk()->assertExists($user->refresh()->avatar);
});

it('validates the file and the flag', function () {
    $user = User::factory()->create();

    test()->actingAs($user)->post(AVATAR_URL, ['name' => 'Ana', 'avatar' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('avatar');
    test()->actingAs($user)->post(AVATAR_URL, ['name' => 'Ana', 'avatar' => UploadedFile::fake()->image('big.jpg')->size(3000)], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('avatar');
    test()->actingAs($user)->postJson(AVATAR_URL, ['name' => 'Ana', 'remove_avatar' => 'maybe'])->assertStatus(422)->assertJsonValidationErrors('remove_avatar');
});

it('never touches the avatar of another user', function () {
    $me = User::factory()->create(['avatar' => null]);
    $other = User::factory()->create();
    avatarDisk()->put('avatars/other.jpg', 'x');
    $other->forceFill(['avatar' => 'avatars/other.jpg'])->save();

    test()->actingAs($me)->postJson(AVATAR_URL, ['name' => 'Ana', 'remove_avatar' => true, 'user_id' => $other->id, 'id' => $other->id])->assertOk();

    avatarDisk()->assertExists('avatars/other.jpg');
    expect($other->refresh()->avatar)->toBe('avatars/other.jpg');
});
