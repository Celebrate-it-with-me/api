<?php

use App\Http\Services\Admin\System\TwoFactorEnforcement;
use App\Mail\AdminInvitationMail;
use App\Models\AdminAuditLog;
use App\Models\AdminInvitation;
use App\Models\AdminTwoFactor;
use App\Models\ImpersonationSession;
use App\Models\User;
use App\Support\AdminPermissions;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\PersonalAccessToken;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

const TI = '/api/v1/admin/team';
const TI_PUBLIC = '/api/v1/admin/invitations';
const TI_REASON = 'Nueva persona de soporte';
const TI_PASSWORD = 'a-very-long-password-123';

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    User::role(AdminPermissions::STAFF_ROLES)->get()->each(fn (User $u) => $u->syncRoles([]));
    Cache::forget(TwoFactorEnforcement::CACHE_KEY);
    config(['admin.require_two_factor_superadmin' => false, 'services.support.admin_url' => 'https://admin.quinvite.test']);
    Mail::fake();
});

function tiStaff(string $role = 'superadmin', array $attrs = []): User
{
    $user = User::factory()->create($attrs);
    $user->assignRole($role);

    return $user;
}

/** A real panel token. Returns the request builder authenticated with it. */
function tiAs(User $user, bool $reauth = true): object
{
    [$plain] = tiToken($user, $reauth);
    app('auth')->forgetGuards();

    return test()->withHeader('Authorization', 'Bearer ' . $plain);
}

/** @return array{0: string, 1: PersonalAccessToken} */
function tiToken(User $user, bool $reauth = true): array
{
    $new = $user->createToken('Chrome 130', ['*']);
    $new->accessToken->forceFill(['client' => 'admin', 'reauthenticated_at' => $reauth ? now() : null])->save();

    return [$new->plainTextToken, $new->accessToken];
}

/** Invites by the API and returns [member, plain token taken from the mail link]. */
function tiInvite(User $by, array $body = []): array
{
    $body += ['email' => 'ana@example.com', 'name' => 'Ana Soporte', 'role' => 'support', 'reason' => TI_REASON];
    $member = tiAs($by)->postJson(TI, $body)->assertCreated()->json('data');

    return [$member, tiLastToken()];
}

function tiLastToken(): string
{
    $mail = Mail::sent(AdminInvitationMail::class)->last();
    parse_str((string) parse_url($mail->link, PHP_URL_QUERY), $query);

    return $query['token'];
}

describe('access', function () {
    it('is closed to anonymous (401), organizers (403) and support (403), open to a superadmin', function () {
        $invitation = AdminInvitation::create(['email' => 'x@example.com', 'name' => 'X', 'role' => 'support', 'token_hash' => AdminInvitation::hashToken('t'), 'expires_at' => now()->addDay(), 'sent_at' => now()]);
        $id = $invitation->memberId();
        $routes = [
            ['GET', TI], ['POST', TI], ['POST', TI . "/{$id}/invitation/resend"], ['DELETE', TI . "/{$id}/invitation"],
            ['POST', TI . '/1/suspend'], ['POST', TI . '/1/reactivate'],
        ];

        foreach ($routes as [$method, $url]) {
            $this->json($method, $url)->assertUnauthorized();
        }
        foreach ($routes as [$method, $url]) {
            $this->actingAs(User::factory()->create())->json($method, $url, ['reason' => TI_REASON])->assertForbidden();
            tiAs(tiStaff('support'))->json($method, $url, ['reason' => TI_REASON])->assertForbidden();
        }

        tiAs(tiStaff())->getJson(TI)->assertOk();
    });

    it('asks for a recent re-authentication on every write', function () {
        $super = tiStaff();
        $other = tiStaff('support');
        [$member] = tiInvite($super);

        $writes = [
            ['POST', TI, ['email' => 'b@example.com', 'name' => 'B', 'role' => 'support', 'reason' => TI_REASON]],
            ['POST', TI . "/{$member['id']}/invitation/resend", []],
            ['DELETE', TI . "/{$member['id']}/invitation", ['reason' => TI_REASON]],
            ['POST', TI . "/{$other->id}/suspend", ['reason' => TI_REASON]],
            ['POST', TI . "/{$other->id}/reactivate", ['reason' => TI_REASON]],
        ];
        foreach ($writes as [$method, $url, $body]) {
            tiAs($super, reauth: false)->json($method, $url, $body)->assertForbidden()->assertJsonPath('code', 'reauth_required');
        }
    });

    it('does not let an impersonation token reach team/*', function () {
        $super = tiStaff();
        $new = $super->createToken('impersonation', [ImpersonationSession::ABILITY]);
        $new->accessToken->forceFill(['client' => 'admin', 'reauthenticated_at' => now()])->save();
        app('auth')->forgetGuards();
        $headers = ['Authorization' => 'Bearer ' . $new->plainTextToken];

        $this->getJson(TI, $headers)->assertForbidden();
        $this->postJson(TI, ['email' => 'b@example.com', 'name' => 'B', 'role' => 'support', 'reason' => TI_REASON], $headers)->assertForbidden();
        expect(AdminInvitation::count())->toBe(0);
    });
});

describe('inviting', function () {
    it('creates the invitation, mails the link and gives no role yet', function () {
        $super = tiStaff();

        [$member, $token] = tiInvite($super);

        expect($member)->toMatchArray(['name' => 'Ana Soporte', 'email' => 'ana@example.com', 'role' => 'support', 'status' => 'invited', 'lastLoginAt' => null, 'twoFactorEnabled' => false])
            ->and($member['invitation'])->toMatchArray(['resendCount' => 0, 'expired' => false])
            ->and($member['id'])->toBe(1_000_000_000 + AdminInvitation::first()->id)
            ->and($member)->not->toHaveKey('temporaryPassword')
            ->and(User::where('email', 'ana@example.com')->exists())->toBeFalse()
            ->and(strlen($token))->toBeGreaterThanOrEqual(40);

        Mail::assertSent(AdminInvitationMail::class, fn ($m) => $m->hasTo('ana@example.com') && $m->link === "https://admin.quinvite.test/accept-invite?token={$token}");
        $invitation = AdminInvitation::first();
        expect($invitation->token_hash)->toBe(hash('sha256', $token))->and($invitation->token_hash)->not->toBe($token)
            ->and($invitation->expires_at->between(now()->addHours(71), now()->addHours(73)))->toBeTrue();
    });

    it('records the invitation without ever writing the token', function () {
        $super = tiStaff();

        [, $token] = tiInvite($super);

        $log = AdminAuditLog::where('action', 'team.invite')->first();
        expect($log->actor_id)->toBe($super->id)->and($log->reason)->toBe(TI_REASON)
            ->and($log->after)->toMatchArray(['email' => 'ana@example.com', 'role' => 'support'])
            ->and(json_encode(AdminAuditLog::all()->toArray()))->not->toContain($token);
        expect(tiAs($super)->getJson(TI)->getContent())->not->toContain($token)->not->toContain(hash('sha256', $token));
    });

    it('needs a reason, a valid role and an email', function () {
        $super = tiStaff();

        tiAs($super)->postJson(TI, ['email' => 'a@example.com', 'name' => 'A', 'role' => 'support'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        tiAs($super)->postJson(TI, ['email' => 'a@example.com', 'name' => 'A', 'role' => 'support', 'reason' => 'hey'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        tiAs($super)->postJson(TI, ['email' => 'a@example.com', 'name' => 'A', 'role' => 'owner', 'reason' => TI_REASON])->assertUnprocessable()->assertJsonValidationErrors('role');
        tiAs($super)->postJson(TI, ['email' => 'nope', 'name' => 'A', 'role' => 'support', 'reason' => TI_REASON])->assertUnprocessable()->assertJsonValidationErrors('email');
        Mail::assertNothingSent();
    });

    it('answers with a code for the rules of the business', function () {
        $super = tiStaff();
        $staff = tiStaff('support', ['email' => 'staff@example.com']);

        tiAs($super)->postJson(TI, ['email' => 'staff@example.com', 'role' => 'support', 'reason' => TI_REASON])->assertUnprocessable()->assertJsonPath('errors.email.0', 'already_staff');
        tiAs($super)->postJson(TI, ['email' => 'new@example.com', 'role' => 'support', 'reason' => TI_REASON])->assertUnprocessable()->assertJsonPath('errors.name.0', 'name_required');
        tiInvite($super);
        tiAs($super)->postJson(TI, ['email' => 'ANA@example.com', 'name' => 'Ana', 'role' => 'superadmin', 'reason' => TI_REASON])->assertUnprocessable()->assertJsonPath('errors.email.0', 'invitation_pending');
        expect(AdminInvitation::count())->toBe(1)->and($staff->refresh()->hasRole('support'))->toBeTrue();
    });

    it('invites an existing Quinvite account without asking for a name', function () {
        $super = tiStaff();
        $organizer = User::factory()->create(['email' => 'lucia@example.com', 'name' => 'Lucía Pérez']);

        [$member] = tiInvite($super, ['email' => 'lucia@example.com', 'name' => null]);

        expect($member['name'])->toBe('Lucía Pérez')->and($organizer->refresh()->hasAnyRole(AdminPermissions::STAFF_ROLES))->toBeFalse();
    });

    it('replaces an expired invitation of the same address, killing the old link', function () {
        $super = tiStaff();
        [, $old] = tiInvite($super);
        AdminInvitation::query()->update(['expires_at' => now()->subMinute()]);

        [$member, $new] = tiInvite($super, ['role' => 'superadmin']);

        expect(AdminInvitation::count())->toBe(1)->and($member['role'])->toBe('superadmin')->and($member['invitation']['expired'])->toBeFalse()->and($new)->not->toBe($old);
        $this->getJson(TI_PUBLIC . "/{$old}")->assertNotFound()->assertJsonPath('code', 'invitation_invalid');
        $this->getJson(TI_PUBLIC . "/{$new}")->assertOk();
    });

    it('survives a mail server that is down and says so', function () {
        $super = tiStaff();
        Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp down'));

        $res = tiAs($super)->postJson(TI, ['email' => 'ana@example.com', 'name' => 'Ana', 'role' => 'support', 'reason' => TI_REASON])->assertCreated();

        expect($res->json('mailSent'))->toBeFalse()->and(AdminInvitation::count())->toBe(1);
    });
});

describe('the list', function () {
    it('shows active first, then invited, then suspended, each by name', function () {
        $super = tiStaff('superadmin', ['name' => 'Zoe Super']);
        tiStaff('support', ['name' => 'Beto Soporte']);
        $suspended = tiStaff('support', ['name' => 'Aida Suspendida', 'admin_suspended_at' => now()]);
        tiInvite($super, ['email' => 'c@example.com', 'name' => 'Carla Invitada']);
        tiInvite($super, ['email' => 'a@example.com', 'name' => 'Abel Invitado']);

        $rows = tiAs($super)->getJson(TI)->assertOk()->json('data');

        expect(collect($rows)->pluck('name')->all())->toBe(['Beto Soporte', 'Zoe Super', 'Abel Invitado', 'Carla Invitada', 'Aida Suspendida'])
            ->and(collect($rows)->pluck('status')->all())->toBe(['active', 'active', 'invited', 'invited', 'suspended'])
            ->and(collect($rows)->firstWhere('id', $suspended->id))->toHaveKeys(['id', 'name', 'email', 'role', 'status', 'lastLoginAt', 'twoFactorEnabled', 'invitation'])
            ->and(collect($rows)->firstWhere('name', 'Zoe Super')['invitation'])->toBeNull();
    });

    it('flags an expired invitation', function () {
        $super = tiStaff();
        tiInvite($super);
        AdminInvitation::query()->update(['expires_at' => now()->subHour()]);

        expect(tiAs($super)->getJson(TI)->json('data.1.invitation.expired'))->toBeTrue();
    });

    it('does not run more queries for more people', function () {
        $super = tiStaff();
        tiStaff('support');
        tiInvite($super);
        $count = function () use ($super) {
            $client = tiAs($super);
            DB::enableQueryLog();
            DB::flushQueryLog();
            $client->getJson(TI)->assertOk();

            return count(DB::getQueryLog());
        };
        $few = $count();

        foreach (range(1, 6) as $i) {
            tiStaff($i % 2 ? 'support' : 'superadmin');
            tiInvite($super, ['email' => "p{$i}@example.com", 'name' => "P{$i}"]);
        }

        expect($count())->toBe($few);
    });

    it('shows when each person last signed in to the panel', function () {
        $super = tiStaff('superadmin', ['password' => 'secret-password-1']);

        $this->postJson('/api/v1/admin/login', ['email' => $super->email, 'password' => 'secret-password-1', 'device' => 'x'])->assertOk();

        $row = collect(tiAs($super)->getJson(TI)->json('data'))->firstWhere('id', $super->id);
        expect($row['lastLoginAt'])->not->toBeNull()->and(now()->diffInSeconds($super->refresh()->admin_last_login_at))->toBeLessThan(5);
    });
});

describe('resending and revoking', function () {
    it('renews the token and the expiry, and the previous link stops working', function () {
        $super = tiStaff();
        [$member, $old] = tiInvite($super);
        AdminInvitation::query()->update(['expires_at' => now()->addHour()]);

        $res = tiAs($super)->postJson(TI . "/{$member['id']}/invitation/resend", [])->assertOk();
        $new = tiLastToken();

        expect($new)->not->toBe($old)->and($res->json('data.invitation.resendCount'))->toBe(1)->and($res->json('data.id'))->toBe($member['id'])
            ->and(AdminInvitation::first()->expires_at->between(now()->addHours(71), now()->addHours(73)))->toBeTrue();
        $this->getJson(TI_PUBLIC . "/{$old}")->assertNotFound()->assertJsonPath('code', 'invitation_invalid');
        $this->getJson(TI_PUBLIC . "/{$new}")->assertOk();
        Mail::assertSent(AdminInvitationMail::class, 2);
        expect(AdminAuditLog::where('action', 'team.invite_resend')->exists())->toBeTrue();
    });

    it('resends an expired invitation, and stops after five resends', function () {
        $super = tiStaff();
        [$member] = tiInvite($super);
        AdminInvitation::query()->update(['expires_at' => now()->subHour()]);

        foreach (range(1, 5) as $i) {
            tiAs($super)->postJson(TI . "/{$member['id']}/invitation/resend", ['reason' => 'El correo no llegó'])->assertOk()->assertJsonPath('data.invitation.resendCount', $i)->assertJsonPath('data.invitation.expired', false);
        }
        // `throttle:6,1` allows six a minute: the limit that answers here is the business one
        tiAs($super)->postJson(TI . "/{$member['id']}/invitation/resend", [])->assertUnprocessable()->assertJsonPath('errors.user.0', 'resend_limit');
        expect(AdminInvitation::first()->resend_count)->toBe(5);
    });

    it('throttles resends to six a minute', function () {
        $super = tiStaff();
        [$member] = tiInvite($super);
        $status = collect(range(1, 8))->map(fn () => tiAs($super)->postJson(TI . "/{$member['id']}/invitation/resend", [])->status());

        expect($status->last())->toBe(429);
    });

    it('answers invitation_invalid when the id is not an invitation', function () {
        $super = tiStaff();
        $support = tiStaff('support');

        tiAs($super)->postJson(TI . "/{$support->id}/invitation/resend", [])->assertNotFound()->assertJsonPath('code', 'invitation_invalid');
        tiAs($super)->deleteJson(TI . "/{$support->id}/invitation", ['reason' => TI_REASON])->assertNotFound()->assertJsonPath('code', 'invitation_invalid');
        tiAs($super)->postJson(TI . '/1000000999/invitation/resend', [])->assertNotFound()->assertJsonPath('code', 'invitation_invalid');
    });

    it('revokes an invitation with a reason and its link dies', function () {
        $super = tiStaff();
        [$member, $token] = tiInvite($super);

        tiAs($super)->deleteJson(TI . "/{$member['id']}/invitation", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        tiAs($super)->deleteJson(TI . "/{$member['id']}/invitation", ['reason' => 'Se equivocó el correo'])->assertOk()->assertExactJson(['data' => null]);

        expect(AdminInvitation::count())->toBe(0)->and(AdminAuditLog::where('action', 'team.invite_revoke')->first()->reason)->toBe('Se equivocó el correo');
        $this->getJson(TI_PUBLIC . "/{$token}")->assertNotFound()->assertJsonPath('code', 'invitation_invalid');
        tiAs($super)->deleteJson(TI . "/{$member['id']}/invitation", ['reason' => TI_REASON])->assertNotFound()->assertJsonPath('code', 'invitation_invalid');
    });

    it('answers member_not_found when a user action targets an invited id', function () {
        $super = tiStaff();
        [$member] = tiInvite($super);
        $id = $member['id'];

        tiAs($super)->patchJson(TI . "/{$id}", ['role' => 'superadmin', 'reason' => TI_REASON])->assertNotFound()->assertJsonPath('code', 'member_not_found');
        tiAs($super)->deleteJson(TI . "/{$id}", ['reason' => TI_REASON])->assertNotFound()->assertJsonPath('code', 'member_not_found');
        tiAs($super)->postJson(TI . "/{$id}/suspend", ['reason' => TI_REASON])->assertNotFound()->assertJsonPath('code', 'member_not_found');
        tiAs($super)->postJson(TI . "/{$id}/reactivate", ['reason' => TI_REASON])->assertNotFound()->assertJsonPath('code', 'member_not_found');
        tiAs($super)->postJson(TI . "/{$id}/two-factor/reset", ['reason' => TI_REASON])->assertNotFound()->assertJsonPath('code', 'member_not_found');
    });
});

describe('looking at an invitation', function () {
    it('shows who it is for', function () {
        $super = tiStaff();
        [, $token] = tiInvite($super);

        $this->getJson(TI_PUBLIC . "/{$token}")->assertOk()->assertJsonPath('data.name', 'Ana Soporte')->assertJsonPath('data.email', 'ana@example.com')
            ->assertJsonPath('data.role', 'support')->assertJsonPath('data.accountExists', false)->assertJsonPath('data.requiresTwoFactor', false)
            ->assertJsonStructure(['data' => ['expiresAt']]);
    });

    it('tells an existing account and whether two-factor is required for that role', function () {
        $super = tiStaff();
        User::factory()->create(['email' => 'lucia@example.com']);
        [, $token] = tiInvite($super, ['email' => 'lucia@example.com', 'role' => 'superadmin']);
        config(['admin.require_two_factor_superadmin' => true]);

        $this->getJson(TI_PUBLIC . "/{$token}")->assertJsonPath('data.accountExists', true)->assertJsonPath('data.requiresTwoFactor', true);
    });

    it('is 404 for a token that does not exist and 410 once it has expired', function () {
        $super = tiStaff();
        [, $token] = tiInvite($super);

        $this->getJson(TI_PUBLIC . '/' . str_repeat('x', 48))->assertNotFound()->assertJsonPath('code', 'invitation_invalid');
        AdminInvitation::query()->update(['expires_at' => now()->subSecond()]);
        $this->getJson(TI_PUBLIC . "/{$token}")->assertStatus(410)->assertJsonPath('code', 'invitation_expired');
        $this->postJson(TI_PUBLIC . "/{$token}/accept", ['password' => TI_PASSWORD, 'password_confirmation' => TI_PASSWORD])->assertStatus(410)->assertJsonPath('code', 'invitation_expired');
        expect(User::where('email', 'ana@example.com')->exists())->toBeFalse();
    });

    it('is throttled to ten a minute', function () {
        $status = collect(range(1, 12))->map(fn () => $this->getJson(TI_PUBLIC . '/nothing')->status());

        expect($status->first())->toBe(404)->and($status->last())->toBe(429);
    });
});

describe('accepting', function () {
    $accept = fn (string $token, array $body = []) => test()->postJson(TI_PUBLIC . "/{$token}/accept", $body + ['password' => TI_PASSWORD, 'password_confirmation' => TI_PASSWORD]);

    it('creates the account, gives the role, verifies the email and signs her in', function () use ($accept) {
        [, $token] = tiInvite(tiStaff());

        $res = $accept($token)->assertOk();

        $user = User::where('email', 'ana@example.com')->first();
        expect($user->name)->toBe('Ana Soporte')->and($user->hasRole('support'))->toBeTrue()->and($user->email_verified_at)->not->toBeNull()
            ->and(Hash::check(TI_PASSWORD, $user->password))->toBeTrue()
            ->and($res->json('data.user'))->toBe(['id' => $user->id, 'name' => 'Ana Soporte', 'email' => 'ana@example.com', 'roles' => ['support']])
            ->and($res->json('data.twoFactorSetupRequired'))->toBeFalse()
            ->and(AdminInvitation::count())->toBe(0);

        app('auth')->forgetGuards();
        $this->withHeader('Authorization', 'Bearer ' . $res->json('data.token'))->getJson('/api/v1/admin/userInfo')->assertOk()->assertJsonPath('data.email', 'ana@example.com');
        $log = AdminAuditLog::where('action', 'team.invite_accept')->first();
        expect($log->actor_id)->toBe($user->id)->and($log->after['accountExisted'])->toBeFalse()->and(json_encode(AdminAuditLog::all()->toArray()))->not->toContain($token)->not->toContain(TI_PASSWORD);
    });

    it('works once: the used link is invalid', function () use ($accept) {
        [, $token] = tiInvite(tiStaff());

        $accept($token)->assertOk();

        $accept($token)->assertNotFound()->assertJsonPath('code', 'invitation_invalid');
        $this->getJson(TI_PUBLIC . "/{$token}")->assertNotFound();
        expect(User::where('email', 'ana@example.com')->count())->toBe(1);
    });

    it('applies the password rules of Mi cuenta', function () use ($accept) {
        [, $token] = tiInvite(tiStaff());

        $accept($token, ['password' => 'short', 'password_confirmation' => 'short'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $accept($token, ['password_confirmation' => 'something-else-entirely'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $accept($token, ['password' => 'ana@example.com', 'password_confirmation' => 'ana@example.com'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $accept($token, ['password' => str_repeat('a', 129), 'password_confirmation' => str_repeat('a', 129)])->assertUnprocessable()->assertJsonValidationErrors('password');
        expect(User::where('email', 'ana@example.com')->exists())->toBeFalse()->and(AdminInvitation::count())->toBe(1);
    });

    it('only the newest link works after a resend', function () use ($accept) {
        $super = tiStaff();
        [$member, $old] = tiInvite($super);
        tiAs($super)->postJson(TI . "/{$member['id']}/invitation/resend", [])->assertOk();
        $new = tiLastToken();

        $accept($old)->assertNotFound()->assertJsonPath('code', 'invitation_invalid');
        $accept($new)->assertOk();
    });

    it('asks to enrol two-factor when the role requires it', function () use ($accept) {
        [, $token] = tiInvite(tiStaff());
        app(TwoFactorEnforcement::class)->set(true, null);

        $res = $accept($token)->assertOk();

        expect($res->json('data.twoFactorSetupRequired'))->toBeTrue();
        app('auth')->forgetGuards();
        $this->withHeader('Authorization', 'Bearer ' . $res->json('data.token'))->getJson('/api/v1/admin/dashboard')->assertForbidden()->assertJsonPath('code', 'two_factor_required');
    });

    it('answers the login challenge when the account already had two-factor on', function () use ($accept) {
        $super = tiStaff();
        $ex = User::factory()->create(['email' => 'ana@example.com', 'password' => 'my-current-password-1']);
        AdminTwoFactor::create(['user_id' => $ex->id, 'secret' => (new Google2FA)->generateSecretKey(), 'confirmed_at' => now(), 'recovery_codes' => []]);
        [, $token] = tiInvite($super);

        $res = $accept($token, ['current_password' => 'my-current-password-1'])->assertOk();

        expect($res->json('twoFactorRequired'))->toBeTrue()->and($res->json('challenge'))->toBeString()->and($res->json('data'))->toBeNull()
            ->and($ex->refresh()->hasRole('support'))->toBeTrue();
    });

    describe('an existing Quinvite account', function () use ($accept) {
        it('confirms with her current password, keeps it, and gets the role', function () use ($accept) {
            $organizer = User::factory()->create(['email' => 'ana@example.com', 'password' => 'my-current-password-1', 'email_verified_at' => null, 'name' => 'Ana Organizadora']);
            $hash = $organizer->password;
            [, $token] = tiInvite(tiStaff());

            $res = $accept($token, ['password' => null, 'password_confirmation' => null, 'current_password' => 'my-current-password-1', 'name' => 'Otro Nombre'])->assertOk();

            $organizer->refresh();
            expect($organizer->password)->toBe($hash)->and($organizer->hasRole('support'))->toBeTrue()->and($organizer->email_verified_at)->not->toBeNull()
                ->and($organizer->name)->toBe('Ana Organizadora') // her account is not renamed
                ->and($res->json('data.token'))->toBeString()
                ->and(AdminAuditLog::where('action', 'team.invite_accept')->first()->after['accountExisted'])->toBeTrue();
        });

        it('ignores any password fields: only current_password is read and hers does not change', function () use ($accept) {
            $organizer = User::factory()->create(['email' => 'ana@example.com', 'password' => 'my-current-password-1']);
            $hash = $organizer->password;
            [, $token] = tiInvite(tiStaff());

            $accept($token, ['current_password' => 'my-current-password-1', 'password' => 'x', 'password_confirmation' => 'does-not-match'])->assertOk();

            expect($organizer->refresh()->password)->toBe($hash)->and($organizer->hasRole('support'))->toBeTrue();
        });

        it('refuses a wrong or missing current password, without giving the role', function () use ($accept) {
            $organizer = User::factory()->create(['email' => 'ana@example.com', 'password' => 'my-current-password-1']);
            [, $token] = tiInvite(tiStaff());

            $accept($token, ['current_password' => 'not-my-password'])->assertUnprocessable()->assertJsonPath('errors.current_password.0', 'invalid_current_password');
            $accept($token)->assertUnprocessable()->assertJsonPath('errors.current_password.0', 'invalid_current_password');
            // the new password of the form must not replace hers either
            expect($organizer->refresh()->hasAnyRole(AdminPermissions::STAFF_ROLES))->toBeFalse()->and(Hash::check('my-current-password-1', $organizer->password))->toBeTrue()
                ->and(AdminInvitation::count())->toBe(1);
        });

        it('locks after five wrong current passwords', function () use ($accept) {
            User::factory()->create(['email' => 'ana@example.com', 'password' => 'my-current-password-1']);
            [, $token] = tiInvite(tiStaff());
            $this->withoutMiddleware(ThrottleRequests::class);

            foreach (range(1, 5) as $i) {
                $accept($token, ['current_password' => 'wrong-' . $i])->assertUnprocessable();
            }
            $accept($token, ['current_password' => 'my-current-password-1'])->assertStatus(429);
        });
    });
});

describe('suspending', function () {
    it('closes the sessions and refuses her with account_suspended until she is reactivated', function () {
        $super = tiStaff();
        $support = tiStaff('support', ['password' => 'secret-password-1']);
        [$plain] = tiToken($support);
        app('auth')->forgetGuards();
        $this->withHeader('Authorization', 'Bearer ' . $plain)->getJson('/api/v1/admin/dashboard')->assertOk();

        $res = tiAs($super)->postJson(TI . "/{$support->id}/suspend", ['reason' => 'Vacaciones largas'])->assertOk();

        expect($res->json('data.status'))->toBe('suspended')->and($support->refresh()->hasRole('support'))->toBeTrue()->and($support->tokens()->count())->toBe(0);
        app('auth')->forgetGuards();
        $this->withHeader('Authorization', 'Bearer ' . $plain)->getJson('/api/v1/admin/dashboard')->assertUnauthorized();
        // a token made after the suspension (a cookie session, say) is refused by the middleware
        $this->actingAs($support)->getJson('/api/v1/admin/dashboard')->assertForbidden()->assertJsonPath('code', 'account_suspended');
        $this->postJson('/api/v1/admin/login', ['email' => $support->email, 'password' => 'secret-password-1', 'device' => 'x'])->assertForbidden()->assertJsonPath('code', 'account_suspended');
        $this->postJson('/api/v1/admin/login', ['email' => $support->email, 'password' => 'wrong', 'device' => 'x'])->assertUnprocessable();

        tiAs($super)->postJson(TI . "/{$support->id}/reactivate", ['reason' => 'Volvió de vacaciones'])->assertOk()->assertJsonPath('data.status', 'active');
        $this->postJson('/api/v1/admin/login', ['email' => $support->email, 'password' => 'secret-password-1', 'device' => 'x'])->assertOk();
        expect(AdminAuditLog::whereIn('action', ['team.suspend', 'team.reactivate'])->orderBy('id')->pluck('action')->all())->toBe(['team.suspend', 'team.reactivate'])
            ->and(AdminAuditLog::where('action', 'team.suspend')->first()->reason)->toBe('Vacaciones largas');
    });

    it('needs a reason', function () {
        $super = tiStaff();
        $support = tiStaff('support');

        tiAs($super)->postJson(TI . "/{$support->id}/suspend", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        tiAs($super)->postJson(TI . "/{$support->id}/reactivate", ['reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('reason');
    });

    it('never suspends yourself or the last active superadmin', function () {
        $super = tiStaff();
        $support = tiStaff('support');
        $support->givePermissionTo('admin.team.manage'); // only so a non-superadmin can try

        tiAs($super)->postJson(TI . "/{$super->id}/suspend", ['reason' => TI_REASON])->assertUnprocessable()->assertJsonPath('errors.user.0', 'cannot_change_self');
        tiAs($support)->postJson(TI . "/{$super->id}/suspend", ['reason' => TI_REASON])->assertUnprocessable()->assertJsonPath('errors.user.0', 'last_superadmin');
        tiAs($support)->patchJson(TI . "/{$super->id}", ['role' => 'support', 'reason' => TI_REASON])->assertUnprocessable()->assertJsonPath('errors.user.0', 'last_superadmin');
        tiAs($support)->deleteJson(TI . "/{$super->id}", ['reason' => TI_REASON])->assertUnprocessable()->assertJsonPath('errors.user.0', 'last_superadmin');
        expect($super->refresh()->hasRole('superadmin'))->toBeTrue()->and($super->admin_suspended_at)->toBeNull();
    });

    it('does not count a suspended superadmin as the one that stays', function () {
        $super = tiStaff();
        $second = tiStaff('superadmin');
        $support = tiStaff('support');
        $support->givePermissionTo('admin.team.manage');

        tiAs($super)->postJson(TI . "/{$second->id}/suspend", ['reason' => TI_REASON])->assertOk();

        // `super` is now the only ACTIVE superadmin: demoting or suspending her is refused
        tiAs($support)->postJson(TI . "/{$super->id}/suspend", ['reason' => TI_REASON])->assertUnprocessable()->assertJsonPath('errors.user.0', 'last_superadmin');
        // the suspended one can be removed: it does not change who is active
        tiAs($super)->deleteJson(TI . "/{$second->id}", ['reason' => TI_REASON])->assertOk()->assertExactJson(['data' => null]);
        expect($second->refresh()->admin_suspended_at)->toBeNull();
    });

    it('answers member_not_found for someone who is not on the team', function () {
        $super = tiStaff();
        $organizer = User::factory()->create();

        tiAs($super)->postJson(TI . "/{$organizer->id}/suspend", ['reason' => TI_REASON])->assertNotFound()->assertJsonPath('code', 'member_not_found');
        tiAs($super)->patchJson(TI . '/999999', ['role' => 'support', 'reason' => TI_REASON])->assertNotFound()->assertJsonPath('code', 'member_not_found');
    });
});
