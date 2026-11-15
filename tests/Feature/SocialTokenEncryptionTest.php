<?php

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function socialRow(User $user, string $id, ?string $access, ?string $refresh): int
{
    return DB::table('social_accounts')->insertGetId([
        'user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => $id,
        'access_token' => $access, 'refresh_token' => $refresh, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('stores OAuth tokens encrypted and reads them back (S-28)', function () {
    $account = SocialAccount::create([
        'user_id' => User::factory()->create()->id, 'provider' => 'google', 'provider_user_id' => 'g-1',
        'access_token' => 'ya29.plain-access', 'refresh_token' => '1//plain-refresh',
    ]);

    $raw = DB::table('social_accounts')->find($account->id);
    expect($raw->access_token)->not->toContain('plain-access')->and($raw->refresh_token)->not->toContain('plain-refresh')
        ->and($account->fresh()->access_token)->toBe('ya29.plain-access')
        ->and($account->fresh()->refresh_token)->toBe('1//plain-refresh')
        ->and($account->fresh()->toArray())->not->toHaveKey('access_token');
});

it('encrypts existing plain tokens once and never re-encrypts', function () {
    $user = User::factory()->create();
    $plain = socialRow($user, 'g-plain', 'ya29.old', '1//old');
    $already = socialRow($user, 'g-enc', Crypt::encryptString('ya29.new'), null);
    $before = DB::table('social_accounts')->find($already)->access_token;

    $migration = include database_path('migrations/2027_04_12_000001_encrypt_social_account_tokens.php');
    $migration->up();
    $migration->up(); // idempotent

    $row = DB::table('social_accounts')->find($plain);
    expect($row->access_token)->not->toBe('ya29.old')
        ->and(SocialAccount::find($plain)->access_token)->toBe('ya29.old')
        ->and(SocialAccount::find($plain)->refresh_token)->toBe('1//old')
        ->and(DB::table('social_accounts')->find($already)->access_token)->toBe($before)
        ->and(SocialAccount::find($already)->access_token)->toBe('ya29.new')
        ->and(SocialAccount::find($already)->refresh_token)->toBeNull();
});
