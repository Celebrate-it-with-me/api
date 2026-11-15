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

it('caps wrong panel passwords per account and address, even for the right one afterwards (S-22)', function () {
    $staff = User::factory()->create(['password' => 'OriginalPass1']);
    $staff->assignRole('support');
    $bad = ['email' => $staff->email, 'password' => 'wrong', 'device' => 'x'];

    foreach (range(1, 5) as $i) {
        $this->postJson('/api/v1/admin/login', $bad)->assertStatus(422);
    }
    $this->postJson('/api/v1/admin/login', ['password' => 'OriginalPass1'] + $bad)->assertStatus(429);
});

it('answers unknown, non staff and wrong password the same (S-22)', function () {
    $user = User::factory()->create(['password' => 'OriginalPass1']);

    $a = $this->postJson('/api/v1/admin/login', ['email' => 'nobody@example.com', 'password' => 'OriginalPass1', 'device' => 'x']);
    $b = $this->postJson('/api/v1/admin/login', ['email' => $user->email, 'password' => 'OriginalPass1', 'device' => 'x']);

    expect($a->status())->toBe(422)->and($b->status())->toBe(422)->and($a->json())->toBe($b->json());
});

it('does not let a staff account with an unverified email into the panel (S-29)', function () {
    $staff = User::factory()->create(['password' => 'OriginalPass1', 'email_verified_at' => null]);
    $staff->assignRole('support');

    $this->postJson('/api/v1/admin/login', ['email' => $staff->email, 'password' => 'OriginalPass1', 'device' => 'x'])->assertStatus(422);

    $staff->forceFill(['email_verified_at' => now()])->save();
    $this->postJson('/api/v1/admin/login', ['email' => $staff->email, 'password' => 'OriginalPass1', 'device' => 'x'])->assertOk();
});
