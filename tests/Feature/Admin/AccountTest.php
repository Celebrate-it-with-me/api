<?php

use App\Mail\AdminEmailConfirmMail;
use App\Mail\AdminSecurityNoticeMail;
use App\Models\AdminAuditLog;
use App\Models\ImpersonationSession;
use App\Models\User;
use App\Models\UserPreference;
use App\Support\AdminPermissions;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

const ACCT_PW = 'old-password-123';
const ACCT = '/api/v1/admin/account';

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    User::role(AdminPermissions::STAFF_ROLES)->get()->each(fn (User $u) => $u->syncRoles([]));
    Mail::fake();
});

function acctStaff(string $role = 'superadmin', array $attrs = []): User
{
    $user = User::factory()->create(['password' => ACCT_PW] + $attrs);
    $user->assignRole($role);

    return $user;
}

/** Real Sanctum token, so the sessions list and "close the others" have something to work on. Returns [plain, model]. */
function acctToken(User $user, string $name = 'Chrome 130', array $abilities = ['*'], string $ua = 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0 Safari/537.36'): array
{
    $new = $user->createToken($name, $abilities);
    $new->accessToken->forceFill(['client' => 'admin', 'ip_address' => '190.12.34.56', 'user_agent' => $ua])->save();

    return [$new->plainTextToken, $new->accessToken];
}

/** A request authenticated by bearer token (the way the panel does it). */
function acctAs(string $plain): object
{
    app('auth')->forgetGuards();

    return test()->withHeader('Authorization', 'Bearer ' . $plain);
}

function acctTotp(string $secret, int $stepOffset = 0): string
{
    $g = new Google2FA;

    return $g->oathTotp($secret, $g->getTimestamp() + $stepOffset);
}

/** Enrols two-factor for the user through the API; returns [secret, recoveryCodes]. */
function acctEnrol(User $user, string $plain): array
{
    $secret = acctAs($plain)->postJson(ACCT . '/two-factor/setup', ['password' => ACCT_PW])->assertOk()->json('data.secret');
    $codes = acctAs($plain)->postJson(ACCT . '/two-factor/confirm', ['code' => acctTotp($secret)])->assertOk()->json('data.recoveryCodes');

    return [$secret, $codes];
}

describe('access', function () {
    $routes = fn () => [
        ['GET', ''], ['PATCH', '/profile'], ['POST', '/email'], ['POST', '/email/confirm'], ['DELETE', '/email'],
        ['POST', '/password'], ['GET', '/two-factor'], ['POST', '/two-factor/setup'], ['POST', '/two-factor/confirm'],
        ['POST', '/two-factor/recovery-codes'], ['POST', '/two-factor/disable'],
        ['GET', '/sessions'], ['DELETE', '/sessions'], ['DELETE', '/sessions/1'], ['POST', '/logout'], ['POST', '/logout-all'], ['GET', '/activity'],
    ];

    it('is closed to anonymous requests (401)', function () use ($routes) {
        foreach ($routes() as [$method, $path]) {
            $this->json($method, ACCT . $path)->assertUnauthorized();
        }
    });

    it('is closed to a normal app user (403)', function () use ($routes) {
        $user = User::factory()->create();
        foreach ($routes() as [$method, $path]) {
            $this->actingAs($user)->json($method, ACCT . $path)->assertForbidden();
        }
    });

    it('is open to support and superadmin on their own account', function (string $role) {
        $user = acctStaff($role);
        $this->actingAs($user)->getJson(ACCT)->assertOk()->assertJsonPath('data.id', $user->id)->assertJsonPath('data.roles.0', $role);
        $this->actingAs($user)->getJson(ACCT . '/sessions')->assertOk();
        $this->actingAs($user)->getJson(ACCT . '/activity')->assertOk();
        $this->actingAs($user)->getJson(ACCT . '/two-factor')->assertOk()->assertJsonPath('data.enabled', false);
    })->with(['support', 'superadmin']);

    it('has no route that takes a user id, so another account cannot be reached', function () {
        $routes = collect(app('router')->getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/admin/account'));
        expect($routes)->not->toBeEmpty();
        foreach ($routes as $route) {
            expect($route->uri())->not->toContain('{user')->and($route->uri())->not->toContain('{staff');
        }
    });

    it('refuses a support-session (impersonation) token, even one held by a staff user', function () {
        $user = acctStaff('support');
        [$plain] = acctToken($user, 'impersonation', [ImpersonationSession::ABILITY]);
        acctAs($plain)->getJson(ACCT)->assertForbidden();
        acctAs($plain)->postJson(ACCT . '/password', ['currentPassword' => ACCT_PW, 'newPassword' => 'another-long-pass-1', 'newPasswordConfirmation' => 'another-long-pass-1'])->assertForbidden();
        acctAs($plain)->postJson(ACCT . '/two-factor/setup', ['password' => ACCT_PW])->assertForbidden();
        expect($user->fresh()->adminTwoFactor)->toBeNull();

        // And a normal user's impersonation token, the real case.
        $normal = User::factory()->create();
        [$imp] = acctToken($normal, 'impersonation', [ImpersonationSession::ABILITY]);
        acctAs($imp)->getJson(ACCT)->assertForbidden();
    });
});

describe('profile', function () {
    it('shows the profile without any secret', function () {
        $user = acctStaff();
        $r = $this->actingAs($user)->getJson(ACCT)->assertOk();
        expect($r->json('data'))->toHaveKeys(['id', 'name', 'email', 'roles', 'language', 'pendingEmail', 'twoFactor'])
            ->and(json_encode($r->json()))->not->toContain('"password"')->not->toContain('secret');
    });

    it('edits the name and the language, and audits who and what', function () {
        $user = acctStaff('support', ['name' => 'Ana Vieja']);
        $this->actingAs($user)->patchJson(ACCT . '/profile', ['name' => '  Ana Nueva ', 'language' => 'en'])
            ->assertOk()->assertJsonPath('data.name', 'Ana Nueva')->assertJsonPath('data.language', 'en');

        expect($user->fresh()->name)->toBe('Ana Nueva')
            ->and(UserPreference::where('user_id', $user->id)->value('language'))->toBe('en');
        $log = AdminAuditLog::where('action', 'account.profile_updated')->sole();
        expect($log->actor_id)->toBe($user->id)->and($log->before['name'])->toBe('Ana Vieja')->and($log->after['language'])->toBe('en')
            ->and($log->ip)->not->toBeNull();
    });

    it('validates name and language', function () {
        $user = acctStaff();
        $this->actingAs($user)->patchJson(ACCT . '/profile', ['name' => 'A'])->assertJsonValidationErrors('name');
        $this->actingAs($user)->patchJson(ACCT . '/profile', ['language' => 'fr'])->assertJsonValidationErrors('language');
    });

    it('cannot change the role or the email through the profile', function () {
        $user = acctStaff('support');
        $this->actingAs($user)->patchJson(ACCT . '/profile', ['name' => 'Ana', 'email' => 'x@example.com', 'role' => 'superadmin', 'id' => 999])->assertOk();
        $fresh = $user->fresh();
        expect($fresh->email)->toBe($user->email)->and($fresh->hasRole('superadmin'))->toBeFalse();
    });
});

describe('email change', function () {
    it('needs the current password', function () {
        $user = acctStaff();
        $this->actingAs($user)->postJson(ACCT . '/email', ['email' => 'nuevo@example.com', 'password' => 'wrong'])->assertJsonValidationErrors('password');
        Mail::assertNothingSent();
        expect($user->adminEmailChange)->toBeNull();
    });

    it('sends the link to the NEW address, changes nothing yet, and confirms with the token', function () {
        $user = acctStaff();
        $old = $user->email;
        $this->actingAs($user)->postJson(ACCT . '/email', ['email' => 'Nuevo@Example.com', 'password' => ACCT_PW])
            ->assertStatus(202)->assertJsonPath('data.pendingEmail.email', 'nuevo@example.com');

        $token = null;
        Mail::assertSent(AdminEmailConfirmMail::class, function ($mail) use (&$token) {
            $token = str($mail->link)->after('token=')->toString();

            return $mail->hasTo('nuevo@example.com');
        });
        expect($user->fresh()->email)->toBe($old)
            ->and(DB::table('admin_email_changes')->value('token_hash'))->not->toBe($token); // only the hash is stored

        $this->actingAs($user)->postJson(ACCT . '/email/confirm', ['token' => 'bad'])->assertJsonValidationErrors('token');
        $this->actingAs($user)->postJson(ACCT . '/email/confirm', ['token' => $token])->assertOk()->assertJsonPath('data.email', 'nuevo@example.com');

        expect($user->fresh()->email)->toBe('nuevo@example.com');
        Mail::assertSent(AdminSecurityNoticeMail::class, fn ($m) => $m->type === 'email_changed' && $m->hasTo($old));
        expect(AdminAuditLog::where('action', 'account.email_changed')->sole()->before['email'])->toBe($old);
        // A link works once.
        $this->actingAs($user)->postJson(ACCT . '/email/confirm', ['token' => $token])->assertJsonValidationErrors('token');
    });

    it('rejects an address in use, the same address, and an expired or foreign link', function () {
        $user = acctStaff();
        $other = acctStaff('support');
        $this->actingAs($user)->postJson(ACCT . '/email', ['email' => strtoupper($other->email), 'password' => ACCT_PW])->assertJsonValidationErrors('email');
        $this->actingAs($user)->postJson(ACCT . '/email', ['email' => $user->email, 'password' => ACCT_PW])->assertJsonValidationErrors('email');

        $this->actingAs($user)->postJson(ACCT . '/email', ['email' => 'otro@example.com', 'password' => ACCT_PW])->assertStatus(202);
        $token = null;
        Mail::assertSent(AdminEmailConfirmMail::class, function ($m) use (&$token) {
            $token = str($m->link)->after('token=')->toString();

            return true;
        });
        // Somebody else, even staff, cannot use her link.
        $this->actingAs($other)->postJson(ACCT . '/email/confirm', ['token' => $token])->assertJsonValidationErrors('token');

        $this->travel(2)->hours();
        $this->actingAs($user)->postJson(ACCT . '/email/confirm', ['token' => $token])->assertJsonValidationErrors('token');
        expect($user->fresh()->email)->not->toBe('otro@example.com');
    });

    it('can be cancelled', function () {
        $user = acctStaff();
        $this->actingAs($user)->postJson(ACCT . '/email', ['email' => 'otro@example.com', 'password' => ACCT_PW])->assertStatus(202);
        $this->actingAs($user)->deleteJson(ACCT . '/email')->assertOk()->assertJsonPath('data.pendingEmail', null);
        expect(AdminAuditLog::where('action', 'account.email_change_cancelled')->count())->toBe(1);
    });
});

describe('password', function () {
    $payload = fn (array $o = []) => $o + ['currentPassword' => ACCT_PW, 'newPassword' => 'a-brand-new-pass-9', 'newPasswordConfirmation' => 'a-brand-new-pass-9'];

    it('requires the current password', function () use ($payload) {
        $user = acctStaff();
        $this->actingAs($user)->postJson(ACCT . '/password', $payload(['currentPassword' => 'nope']))->assertJsonValidationErrors('currentPassword');
        expect(password_verify(ACCT_PW, $user->fresh()->password))->toBeTrue();
    });

    it('enforces strength: 12+ characters, different from the old one, confirmed, not the email', function () use ($payload) {
        $user = acctStaff();
        $this->actingAs($user)->postJson(ACCT . '/password', $payload(['newPassword' => 'short-pw-1', 'newPasswordConfirmation' => 'short-pw-1']))->assertJsonValidationErrors('newPassword');
        $this->actingAs($user)->postJson(ACCT . '/password', $payload(['newPassword' => ACCT_PW . 'xxxxxxxx', 'newPasswordConfirmation' => 'different']))->assertJsonValidationErrors('newPasswordConfirmation');
        $this->actingAs($user)->postJson(ACCT . '/password', ['currentPassword' => ACCT_PW, 'newPassword' => ACCT_PW, 'newPasswordConfirmation' => ACCT_PW])->assertJsonValidationErrors('newPassword');
        $this->actingAs($user)->postJson(ACCT . '/password', $payload(['newPassword' => strtoupper($user->email), 'newPasswordConfirmation' => strtoupper($user->email)]))->assertJsonValidationErrors('newPassword');
    });

    it('changes it, keeps this session, closes the others, mails a notice and audits without the password', function () use ($payload) {
        $user = acctStaff();
        [$here, $hereModel] = acctToken($user);
        acctToken($user, 'Firefox');
        acctToken($user, 'Safari');

        acctAs($here)->postJson(ACCT . '/password', $payload())->assertOk()->assertJsonPath('data.sessionsClosed', 2);

        expect($user->tokens()->pluck('id')->all())->toBe([$hereModel->id])
            ->and(password_verify('a-brand-new-pass-9', $user->fresh()->password))->toBeTrue()
            ->and($user->fresh()->password_changed_at)->not->toBeNull();
        Mail::assertSent(AdminSecurityNoticeMail::class, fn ($m) => $m->type === 'password_changed' && $m->hasTo($user->email));
        $log = AdminAuditLog::where('action', 'account.password_changed')->sole();
        expect(json_encode($log->toArray()))->not->toContain('a-brand-new-pass')->not->toContain(ACCT_PW);
        // The session that did it still works.
        acctAs($here)->getJson(ACCT)->assertOk();
        // The old password no longer opens the panel.
        $this->postJson('/api/v1/admin/login', ['email' => $user->email, 'password' => ACCT_PW, 'device' => 'x'])->assertStatus(422);
    });

    it('locks after five wrong current passwords, for every action that asks for it', function () use ($payload) {
        $user = acctStaff();
        foreach (range(1, 5) as $i) {
            $this->actingAs($user)->postJson(ACCT . '/password', $payload(['currentPassword' => "bad-$i"]))->assertStatus(422);
        }
        $this->actingAs($user)->postJson(ACCT . '/password', $payload())->assertStatus(429);
        $this->actingAs($user)->postJson(ACCT . '/two-factor/setup', ['password' => ACCT_PW])->assertStatus(429);
        expect(password_verify(ACCT_PW, $user->fresh()->password))->toBeTrue();
    });

    it('is throttled per person on the endpoint', function () {
        $user = acctStaff();
        $statuses = [];
        foreach (range(1, 12) as $i) {
            $statuses[] = $this->actingAs($user)->postJson(ACCT . '/password', ['currentPassword' => ''])->getStatusCode();
        }
        expect($statuses)->toContain(429);
    });
});

describe('two-factor', function () {
    it('asks for the password to start, and returns the secret and the otpauth URI only there', function () {
        $user = acctStaff();
        $this->actingAs($user)->postJson(ACCT . '/two-factor/setup', [])->assertJsonValidationErrors('password');
        $this->actingAs($user)->postJson(ACCT . '/two-factor/setup', ['password' => 'wrong'])->assertJsonValidationErrors('password');

        $r = $this->actingAs($user)->postJson(ACCT . '/two-factor/setup', ['password' => ACCT_PW])->assertOk();
        $secret = $r->json('data.secret');
        expect($secret)->toHaveLength(32)
            ->and($r->json('data.otpauthUrl'))->toStartWith('otpauth://totp/')->toContain($secret)->toContain('issuer=');

        // Not active until confirmed.
        $this->actingAs($user)->getJson(ACCT . '/two-factor')->assertJsonPath('data.enabled', false);
        // The secret is encrypted at rest.
        expect(DB::table('admin_two_factor')->value('secret'))->not->toContain($secret);
    });

    it('activates only with a valid code, then shows eight recovery codes once, stored hashed', function () {
        $user = acctStaff();
        $secret = $this->actingAs($user)->postJson(ACCT . '/two-factor/setup', ['password' => ACCT_PW])->json('data.secret');

        $this->actingAs($user)->postJson(ACCT . '/two-factor/confirm', ['code' => '000000'])->assertJsonValidationErrors('code');
        $this->actingAs($user)->postJson(ACCT . '/two-factor/confirm', ['code' => 'abcdef'])->assertJsonValidationErrors('code');
        $this->actingAs($user)->getJson(ACCT . '/two-factor')->assertJsonPath('data.enabled', false);

        $r = $this->actingAs($user)->postJson(ACCT . '/two-factor/confirm', ['code' => acctTotp($secret)])->assertOk();
        $codes = $r->json('data.recoveryCodes');
        expect($codes)->toHaveCount(8)->and(array_unique($codes))->toHaveCount(8)->and($codes[0])->toMatch('/^[A-Z2-9]{5}-[A-Z2-9]{5}$/')
            ->and($r->json('data.enabled'))->toBeTrue()->and($r->json('data.recoveryCodesRemaining'))->toBe(8);

        $raw = (string) DB::table('admin_two_factor')->value('recovery_codes');
        foreach ($codes as $c) {
            expect($raw)->not->toContain($c)->not->toContain(str_replace('-', '', $c));
        }
        Mail::assertSent(AdminSecurityNoticeMail::class, fn ($m) => $m->type === 'two_factor_enabled');
        expect(AdminAuditLog::where('action', 'account.two_factor_enabled')->count())->toBe(1);

        // Starting again once active is refused.
        $this->actingAs($user)->postJson(ACCT . '/two-factor/setup', ['password' => ACCT_PW])->assertStatus(409);
        $this->actingAs($user)->postJson(ACCT . '/two-factor/confirm', ['code' => acctTotp($secret, 1)])->assertStatus(409);
    });

    it('never leaks the secret or the codes in any other response, nor in the audit log', function () {
        $user = acctStaff();
        [$plain] = acctToken($user);
        [$secret, $codes] = acctEnrol($user, $plain);

        $bodies = [
            acctAs($plain)->getJson(ACCT)->getContent(),
            acctAs($plain)->getJson(ACCT . '/two-factor')->getContent(),
            acctAs($plain)->getJson(ACCT . '/sessions')->getContent(),
            acctAs($plain)->getJson(ACCT . '/activity')->getContent(),
            acctAs($plain)->getJson('/api/v1/admin/userInfo')->getContent(),
            acctAs($plain)->getJson('/api/v1/admin/audit')->getContent(),
            json_encode(AdminAuditLog::all()->toArray()),
            json_encode($user->fresh()->toArray()),
            json_encode($user->fresh()->adminTwoFactor->toArray()),
        ];
        foreach ($bodies as $body) {
            expect($body)->not->toContain($secret);
            foreach ($codes as $c) {
                expect($body)->not->toContain($c);
            }
        }
    });

    it('asks for the second step at login, and gives the token only with a valid code', function () {
        $user = acctStaff();
        [$plain] = acctToken($user);
        [$secret] = acctEnrol($user, $plain);
        $user->adminTwoFactor->update(['last_used_step' => null]);

        $step1 = $this->postJson('/api/v1/admin/login', ['email' => $user->email, 'password' => ACCT_PW, 'device' => 'Chrome'])->assertOk();
        expect($step1->json('twoFactorRequired'))->toBeTrue()->and($step1->json('challenge'))->toBeString();
        $tokensBefore = $user->tokens()->count();
        $challenge = $step1->json('challenge');

        $this->postJson('/api/v1/admin/login/two-factor', ['challenge' => $challenge, 'code' => '123456', 'device' => 'Chrome'])->assertJsonValidationErrors('code');
        expect($user->tokens()->count())->toBe($tokensBefore);
        $this->postJson('/api/v1/admin/login/two-factor', ['challenge' => 'nonsense', 'code' => acctTotp($secret), 'device' => 'Chrome'])->assertJsonValidationErrors('challenge');

        $ok = $this->postJson('/api/v1/admin/login/two-factor', ['challenge' => $challenge, 'code' => acctTotp($secret), 'device' => 'Chrome'])->assertOk();
        acctAs($ok->getContent())->getJson('/api/v1/admin/userInfo')->assertOk()->assertJsonPath('data.security.twoFactorEnabled', true);
        expect($user->tokens()->count())->toBe($tokensBefore + 1);

        // The challenge works once, and the same code cannot be replayed.
        $this->postJson('/api/v1/admin/login/two-factor', ['challenge' => $challenge, 'code' => acctTotp($secret), 'device' => 'Chrome'])->assertJsonValidationErrors('challenge');
        $again = $this->postJson('/api/v1/admin/login', ['email' => $user->email, 'password' => ACCT_PW, 'device' => 'Chrome'])->json('challenge');
        $this->postJson('/api/v1/admin/login/two-factor', ['challenge' => $again, 'code' => acctTotp($secret), 'device' => 'Chrome'])->assertJsonValidationErrors('code');
    });

    it('does not hand out a token for a wrong password even with two-factor on, and a wrong password does not open a challenge', function () {
        $user = acctStaff();
        [$plain] = acctToken($user);
        acctEnrol($user, $plain);

        $this->postJson('/api/v1/admin/login', ['email' => $user->email, 'password' => 'wrong', 'device' => 'x'])->assertStatus(422)->assertJsonMissingPath('challenge');
        expect(AdminAuditLog::where('action', 'auth.login_failed')->where('actor_id', $user->id)->count())->toBe(1);
    });

    it('kills the challenge after five wrong codes', function () {
        $user = acctStaff();
        [$plain] = acctToken($user);
        [$secret] = acctEnrol($user, $plain);
        $user->adminTwoFactor->update(['last_used_step' => null]);

        $challenge = $this->postJson('/api/v1/admin/login', ['email' => $user->email, 'password' => ACCT_PW, 'device' => 'x'])->json('challenge');
        foreach (range(1, 5) as $i) {
            $this->postJson('/api/v1/admin/login/two-factor', ['challenge' => $challenge, 'code' => '111111', 'device' => 'x']);
        }
        // Even the right code is refused now: she must start over.
        $this->postJson('/api/v1/admin/login/two-factor', ['challenge' => $challenge, 'code' => acctTotp($secret), 'device' => 'x'])->assertJsonValidationErrors('challenge');
        expect(AdminAuditLog::where('action', 'account.two_factor_failed')->count())->toBe(5);
    });

    it('accepts each recovery code once, in any case and with or without the dash', function () {
        $user = acctStaff();
        [$plain] = acctToken($user);
        [, $codes] = acctEnrol($user, $plain);
        $login = fn (string $code) => $this->postJson('/api/v1/admin/login/two-factor', [
            'challenge' => $this->postJson('/api/v1/admin/login', ['email' => $user->email, 'password' => ACCT_PW, 'device' => 'x'])->json('challenge'),
            'recoveryCode' => $code, 'device' => 'x',
        ]);

        $login(strtolower($codes[0]))->assertOk();
        $login($codes[0])->assertJsonValidationErrors('recoveryCode'); // used
        $login(str_replace('-', '', $codes[1]))->assertOk();
        $login('AAAAA-BBBBB')->assertJsonValidationErrors('recoveryCode');

        $this->actingAs($user)->getJson(ACCT . '/two-factor')->assertJsonPath('data.recoveryCodesRemaining', 6);
        expect(AdminAuditLog::where('action', 'account.recovery_code_used')->count())->toBe(2);
        // The used code is not in any audit row.
        expect(json_encode(AdminAuditLog::all()->toArray()))->not->toContain($codes[0]);
    });

    it('regenerates recovery codes (password required) and the old ones stop working', function () {
        $user = acctStaff();
        [$plain] = acctToken($user);
        [, $old] = acctEnrol($user, $plain);

        acctAs($plain)->postJson(ACCT . '/two-factor/recovery-codes', [])->assertJsonValidationErrors('password');
        acctAs($plain)->postJson(ACCT . '/two-factor/recovery-codes', ['password' => 'wrong'])->assertJsonValidationErrors('password');
        $new = acctAs($plain)->postJson(ACCT . '/two-factor/recovery-codes', ['password' => ACCT_PW])->assertOk()->json('data.recoveryCodes');
        expect($new)->toHaveCount(8)->and(array_intersect($new, $old))->toBe([]);

        $challenge = $this->postJson('/api/v1/admin/login', ['email' => $user->email, 'password' => ACCT_PW, 'device' => 'x'])->json('challenge');
        $this->postJson('/api/v1/admin/login/two-factor', ['challenge' => $challenge, 'recoveryCode' => $old[0], 'device' => 'x'])->assertJsonValidationErrors('recoveryCode');
        $this->postJson('/api/v1/admin/login/two-factor', ['challenge' => $challenge, 'recoveryCode' => $new[0], 'device' => 'x'])->assertOk();
        Mail::assertSent(AdminSecurityNoticeMail::class, fn ($m) => $m->type === 'recovery_codes_regenerated');
    });

    it('refuses to regenerate when it is not active', function () {
        $user = acctStaff();
        $this->actingAs($user)->postJson(ACCT . '/two-factor/recovery-codes', ['password' => ACCT_PW])->assertStatus(409);
    });

    it('disables with the password, closes the other sessions and goes back to one step', function () {
        $user = acctStaff();
        [$here, $model] = acctToken($user);
        acctToken($user, 'Other');
        acctEnrol($user, $here);

        acctAs($here)->postJson(ACCT . '/two-factor/disable', [])->assertJsonValidationErrors('password');
        acctAs($here)->postJson(ACCT . '/two-factor/disable', ['password' => ACCT_PW])->assertOk()->assertJsonPath('data.enabled', false);

        expect($user->tokens()->pluck('id')->all())->toBe([$model->id])
            ->and($user->fresh()->adminTwoFactor)->toBeNull();
        Mail::assertSent(AdminSecurityNoticeMail::class, fn ($m) => $m->type === 'two_factor_disabled');
        expect(AdminAuditLog::where('action', 'account.two_factor_disabled')->count())->toBe(1);
        $login = $this->postJson('/api/v1/admin/login', ['email' => $user->email, 'password' => ACCT_PW, 'device' => 'x'])->assertOk();
        expect(json_decode($login->getContent(), true))->toBeNull(); // a plain token, no challenge
    });

    it('does not let one person touch the enrolment of another', function () {
        $a = acctStaff();
        $b = acctStaff('support');
        [$plainB] = acctToken($b);
        acctEnrol($b, $plainB);

        $this->actingAs($a)->getJson(ACCT . '/two-factor')->assertJsonPath('data.enabled', false);
        $this->actingAs($a)->postJson(ACCT . '/two-factor/disable', ['password' => ACCT_PW])->assertOk();
        expect($b->fresh()->hasAdminTwoFactor())->toBeTrue();
    });

    it('is reset from the console for a person who lost everything', function () {
        $user = acctStaff();
        [$plain] = acctToken($user);
        acctEnrol($user, $plain);

        $this->artisan('admin:two-factor-reset', ['email' => $user->email])->assertSuccessful();
        expect($user->fresh()->hasAdminTwoFactor())->toBeFalse()->and($user->tokens()->count())->toBe(0)
            ->and(AdminAuditLog::where('action', 'account.two_factor_reset_cli')->count())->toBe(1);
        $this->artisan('admin:two-factor-reset', ['email' => 'nobody@example.com'])->assertFailed();
    });
});

describe('required for superadmin', function () {
    it('is off by default: nobody is forced', function () {
        $user = acctStaff();
        $this->actingAs($user)->getJson('/api/v1/admin/dashboard')->assertOk();
        $this->actingAs($user)->getJson('/api/v1/admin/userInfo')->assertJsonPath('data.security.twoFactorSetupRequired', false);
    });

    it('blocks a superadmin without two-factor everywhere but her account, and lets support through', function () {
        config(['admin.require_two_factor_superadmin' => true]);
        $super = acctStaff();
        $support = acctStaff('support');

        $this->actingAs($super)->getJson('/api/v1/admin/dashboard')->assertForbidden()->assertJsonPath('code', 'two_factor_required');
        $this->actingAs($super)->getJson('/api/v1/admin/users')->assertForbidden()->assertJsonPath('code', 'two_factor_required');
        $this->actingAs($super)->getJson('/api/v1/admin/userInfo')->assertOk()->assertJsonPath('data.security.twoFactorSetupRequired', true);
        $this->actingAs($super)->getJson(ACCT)->assertOk()->assertJsonPath('data.twoFactor.required', true);
        $this->actingAs($super)->postJson(ACCT . '/two-factor/setup', ['password' => ACCT_PW])->assertOk();

        $this->actingAs($support)->getJson('/api/v1/admin/dashboard')->assertOk();
    });

    it('opens again once she has enrolled', function () {
        config(['admin.require_two_factor_superadmin' => true]);
        $super = acctStaff();
        [$plain] = acctToken($super);
        acctEnrol($super, $plain);

        acctAs($plain)->getJson('/api/v1/admin/dashboard')->assertOk();
    });
});

describe('sessions', function () {
    it('lists only her own sessions, marks the current one and masks the address', function () {
        $user = acctStaff();
        $other = acctStaff('support');
        [$here] = acctToken($user, 'Chrome 130');
        acctToken($user, 'Firefox 120', ua: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15) Gecko/20100101 Firefox/120.0');
        acctToken($other, 'Not mine');
        acctToken($user, 'impersonation', [ImpersonationSession::ABILITY]);

        $rows = acctAs($here)->getJson(ACCT . '/sessions')->assertOk()->json('data');
        expect($rows)->toHaveCount(2)
            ->and(collect($rows)->where('current', true))->toHaveCount(1)
            ->and(collect($rows)->pluck('device')->sort()->values()->all())->toBe(['Chrome en Windows', 'Firefox en macOS'])
            ->and($rows[0]['ip'])->toBe('190.12.34.x')
            ->and(json_encode($rows))->not->toContain('190.12.34.56')->not->toContain('Not mine');
    });

    it('closes one session of hers, never the current one, and a stranger\'s id is a 404', function () {
        $user = acctStaff();
        $other = acctStaff('support');
        [$here, $hereModel] = acctToken($user);
        [, $second] = acctToken($user, 'Second');
        [, $foreign] = acctToken($other, 'Foreign');

        acctAs($here)->deleteJson(ACCT . '/sessions/' . $hereModel->id)->assertStatus(422)->assertJsonPath('code', 'current_session');
        acctAs($here)->deleteJson(ACCT . '/sessions/' . $foreign->id)->assertNotFound();
        expect($foreign->fresh())->not->toBeNull();

        acctAs($here)->deleteJson(ACCT . '/sessions/' . $second->id)->assertOk();
        expect($second->fresh())->toBeNull()
            ->and(AdminAuditLog::where('action', 'account.session_revoked')->sole()->after['sessionId'])->toBe($second->id);
    });

    it('closes all the others and keeps this one', function () {
        $user = acctStaff();
        [$here, $model] = acctToken($user);
        acctToken($user, 'B');
        acctToken($user, 'C');
        [$otherPlain] = acctToken(acctStaff('support'));

        acctAs($here)->deleteJson(ACCT . '/sessions')->assertOk()->assertJsonPath('data.closed', 2);
        expect($user->tokens()->pluck('id')->all())->toBe([$model->id]);
        acctAs($otherPlain)->getJson(ACCT)->assertOk();
        acctAs($here)->getJson(ACCT)->assertOk();
    });

    it('signs out on the server: the token stops working', function () {
        $user = acctStaff();
        [$here] = acctToken($user);
        [$other] = acctToken($user, 'B');

        acctAs($here)->postJson(ACCT . '/logout')->assertOk();
        acctAs($here)->getJson(ACCT)->assertUnauthorized();
        acctAs($other)->getJson(ACCT)->assertOk();
        expect(AdminAuditLog::where('action', 'account.logout')->count())->toBe(1);
    });

    it('signs out everywhere', function () {
        $user = acctStaff();
        [$here] = acctToken($user);
        [$other] = acctToken($user, 'B');

        acctAs($here)->postJson(ACCT . '/logout-all')->assertOk()->assertJsonPath('data.closed', 2);
        acctAs($here)->getJson(ACCT)->assertUnauthorized();
        acctAs($other)->getJson(ACCT)->assertUnauthorized();
        expect($user->tokens()->count())->toBe(0);
    });
});

describe('activity and login', function () {
    it('shows only her own sign-ins and account changes, newest first', function () {
        $user = acctStaff();
        $other = acctStaff('support');
        $this->postJson('/api/v1/admin/login', ['email' => $user->email, 'password' => ACCT_PW, 'device' => 'x'], ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0'])->assertOk();
        $this->postJson('/api/v1/admin/login', ['email' => $other->email, 'password' => ACCT_PW, 'device' => 'x'])->assertOk();
        $this->actingAs($user)->patchJson(ACCT . '/profile', ['name' => 'Nombre Nuevo'])->assertOk();
        $this->actingAs($other)->patchJson(ACCT . '/profile', ['name' => 'Otro Nombre'])->assertOk();

        $rows = $this->actingAs($user)->getJson(ACCT . '/activity')->assertOk()->json('data');
        expect(collect($rows)->pluck('action')->all())->toBe(['account.profile_updated', 'auth.login'])
            ->and(AdminAuditLog::where('actor_id', $other->id)->count())->toBe(2)
            ->and(collect($rows)->last()['detail']['method'])->toBe('password')
            ->and(collect($rows)->last()['device'])->toBe('Chrome en Windows');
    });

    it('stores the device on the token at login and mails a notice for a NEW device only', function () {
        $user = acctStaff();
        $login = fn (string $ua) => $this->postJson('/api/v1/admin/login', ['email' => $user->email, 'password' => ACCT_PW, 'device' => 'x'], ['User-Agent' => $ua])->assertOk();
        $chrome = 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0 Safari/537.36';
        $firefox = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15) Gecko/20100101 Firefox/120.0';

        $login($chrome);
        Mail::assertNotSent(AdminSecurityNoticeMail::class); // the first sign-in is not "new"
        $login($chrome);
        Mail::assertNotSent(AdminSecurityNoticeMail::class);
        $login($firefox);
        Mail::assertSent(AdminSecurityNoticeMail::class, fn ($m) => $m->type === 'new_device' && $m->details['device'] === 'Firefox en macOS' && $m->hasTo($user->email));

        $token = $user->tokens()->latest('id')->first();
        expect($token->client)->toBe('admin')->and($token->user_agent)->toBe($firefox)->and($token->ip_address)->not->toBeNull();
    });

    it('limits sign-in attempts per address', function () {
        $user = acctStaff();
        $statuses = [];
        foreach (range(1, 12) as $i) {
            $statuses[] = $this->postJson('/api/v1/admin/login', ['email' => $user->email, 'password' => 'wrong', 'device' => 'x'])->getStatusCode();
        }
        expect($statuses)->toContain(429);
    });
});
