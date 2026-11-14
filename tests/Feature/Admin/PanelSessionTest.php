<?php

use App\Http\Services\Admin\Account\TwoFactorService;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

function psStaffWithTwoFactor(): User
{
    $staff = User::factory()->create(['password' => 'OriginalPass1', 'email_verified_at' => now()]);
    $staff->assignRole('superadmin');
    $svc = app(TwoFactorService::class);
    $setup = $svc->startSetup($staff);
    $svc->confirmSetup($staff->fresh(), (new Google2FA)->getCurrentOtp($setup['secret']));

    return $staff->fresh();
}

it('rejects the cookie session of the organizers login on every panel route (S-02)', function () {
    $staff = psStaffWithTwoFactor();
    $victim = User::factory()->create();
    $h = ['Origin' => 'http://dev.app.cwm.com', 'Referer' => 'http://dev.app.cwm.com/'];

    $this->withHeaders($h)->get('/sanctum/csrf-cookie');
    $this->withHeaders($h)->postJson('/api/v1/app/login', ['email' => $staff->email, 'password' => 'OriginalPass1', 'device' => 'x'])->assertOk();

    foreach ([
        ['getJson', '/api/v1/admin/userInfo'],
        ['getJson', '/api/v1/admin/users'],
        ['getJson', '/api/v1/admin/audit'],
        ['getJson', "/api/v1/admin/users/{$victim->id}/export"],
        ['postJson', '/api/v1/admin/account/two-factor/disable'],
        ['postJson', "/api/v1/admin/users/{$victim->id}/impersonate"],
    ] as [$method, $url]) {
        $this->withHeaders($h)->{$method}($url)->assertStatus(401)->assertJsonPath('code', 'admin_session_required');
    }

    expect($staff->fresh()->hasAdminTwoFactor())->toBeTrue();
});

it('rejects a plain cookie session and a token that is not from the panel login', function () {
    $staff = User::factory()->create();
    $staff->assignRole('support');

    $this->actingAsCookie($staff)->getJson('/api/v1/admin/users')->assertStatus(401);

    $appToken = $staff->createToken('app')->plainTextToken; // client null: not issued by admin/login
    $this->withToken($appToken)->getJson('/api/v1/admin/users')->assertStatus(401);
});

it('still lets a real panel token through', function () {
    $staff = User::factory()->create();
    $staff->assignRole('support');
    $new = $staff->createToken('panel');
    $new->accessToken->forceFill(['client' => 'admin'])->save();

    $this->withToken($new->plainTextToken)->getJson('/api/v1/admin/users')->assertOk();
});

it('keeps non staff out with 403 before looking at the token', function () {
    $this->actingAsCookie(User::factory()->create())->getJson('/api/v1/admin/users')->assertForbidden();
});

it('suspending the panel access closes only her panel sessions', function () {
    $super = User::factory()->create(['password' => 'OriginalPass1']);
    $super->assignRole('superadmin');
    $member = User::factory()->create();
    $member->assignRole('support');
    $member->createToken('panel')->accessToken->forceFill(['client' => 'admin'])->save();
    $member->createToken('other');

    $this->actingAsReauthed($super)
        ->postJson("/api/v1/admin/team/{$member->id}/suspend", ['reason' => 'Security review of the account'])
        ->assertOk();

    expect($member->tokens()->where('client', 'admin')->count())->toBe(0)
        ->and($member->tokens()->count())->toBe(1);
});
