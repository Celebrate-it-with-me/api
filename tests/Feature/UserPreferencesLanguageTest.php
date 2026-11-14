<?php

use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('defaults the language column to Spanish', function () {
    $user = User::factory()->create();
    DB::table('user_preferences')->insert(['user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);

    expect(DB::table('user_preferences')->where('user_id', $user->id)->value('language'))->toBe('es');
});

it('creates preferences in Spanish when the request does not send a language, without nulling other fields', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/v1/app/user/preferences', ['timezone' => 'America/Chicago'])->assertSuccessful();

    $row = UserPreference::where('user_id', $user->id)->firstOrFail();
    expect($row->language)->toBe('es')->and($row->timezone)->toBe('America/Chicago')->and($row->visual_theme)->toBe('system');
});

it('keeps the language the person chose and does not overwrite fields that were not sent', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/v1/app/user/preferences', ['language' => 'en', 'notifyByEmail' => false])->assertSuccessful();
    $this->actingAs($user)->postJson('/api/v1/app/user/preferences', ['timezone' => 'America/Chicago'])->assertSuccessful();

    $row = UserPreference::where('user_id', $user->id)->firstOrFail();
    expect($row->language)->toBe('en')->and($row->timezone)->toBe('America/Chicago')->and($row->notify_by_email)->toBeFalse();
});

it('does not touch existing rows when the migration runs (only the default changes)', function () {
    $user = User::factory()->create();
    DB::table('user_preferences')->insert(['user_id' => $user->id, 'language' => 'en', 'created_at' => now(), 'updated_at' => now()]);
    $migration = require database_path('migrations/2026_12_90_000001_default_user_language_to_spanish.php');

    $migration->down();
    expect(DB::selectOne("select column_default from information_schema.columns where table_name = 'user_preferences' and column_name = 'language'")->column_default)->toContain("'en'");
    $migration->up();

    expect(DB::selectOne("select column_default from information_schema.columns where table_name = 'user_preferences' and column_name = 'language'")->column_default)->toContain("'es'")
        ->and(DB::table('user_preferences')->where('user_id', $user->id)->value('language'))->toBe('en');
});
