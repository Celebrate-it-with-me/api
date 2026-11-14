<?php

use App\Http\Services\Admin\Impersonation\ImpersonationService;
use App\Mail\AdminSecurityNoticeMail;
use App\Models\AdminAuditLog;
use App\Models\AdminTwoFactor;
use App\Models\Coupon;
use App\Models\EventPlan;
use App\Models\EventPurchase;
use App\Models\Events;
use App\Models\EventType;
use App\Models\User;
use App\Support\AdminPermissions;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

const HD_PW = 'old-password-123';
const HD = '/api/v1/admin';

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    User::role(AdminPermissions::STAFF_ROLES)->get()->each(fn (User $u) => $u->syncRoles([]));
    Mail::fake();
    RateLimiter::clear('admin-reauth:1');
});

function hdStaff(string $role = 'superadmin', array $attrs = []): User
{
    $user = User::factory()->create(['password' => HD_PW] + $attrs);
    $user->assignRole($role);

    return $user;
}

/** A real panel token. Returns [plain, model]. */
function hdToken(User $user, bool $reauth = false, ?string $client = 'admin'): array
{
    $new = $user->createToken('Chrome 130', ['*']);
    $new->accessToken->forceFill(['client' => $client, 'reauthenticated_at' => $reauth ? now() : null])->save();

    return [$new->plainTextToken, $new->accessToken];
}

function hdAs(string $plain): object
{
    app('auth')->forgetGuards();

    return test()->withHeader('Authorization', 'Bearer ' . $plain);
}

function hdEnrol(User $user): string
{
    $secret = (new Google2FA)->generateSecretKey();
    AdminTwoFactor::create(['user_id' => $user->id, 'secret' => $secret, 'confirmed_at' => now(), 'recovery_codes' => []]);
    $user->unsetRelation('adminTwoFactor');

    return $secret;
}

/** A valid code that was not used before; the library refuses a step it already accepted, so each call moves one step on. */
function hdCode(string $secret, int $step = 0): string
{
    $g = new Google2FA;

    return $g->oathTotp($secret, $g->getTimestamp() + $step);
}

describe('token lifetime', function () {
    it('lets a fresh token through', function () {
        [$plain] = hdToken(hdStaff());
        hdAs($plain)->getJson(HD . '/userInfo')->assertOk();
    });

    it('expires a token left idle for more than 12 hours: 401 session_expired, token revoked and audited', function () {
        $user = hdStaff();
        [$plain, $token] = hdToken($user);
        $this->freezeTime();

        $this->travel(11)->hours();
        hdAs($plain)->getJson(HD . '/userInfo')->assertOk();

        $this->travel(12 * 60 + 1)->minutes();
        hdAs($plain)->getJson(HD . '/userInfo')->assertUnauthorized()
            ->assertJsonPath('code', 'session_expired')->assertJsonPath('reason', 'idle');

        expect(PersonalAccessToken::find($token->id))->toBeNull();
        $row = AdminAuditLog::where('action', 'account.session_expired')->firstOrFail();
        expect($row->actor_id)->toBe($user->id)->and($row->after['reason'])->toBe('idle');
        // The token is gone: asking again is the plain unauthenticated answer.
        hdAs($plain)->getJson(HD . '/userInfo')->assertUnauthorized()->assertJsonMissingPath('code');
    });

    it('slides: using the token keeps the idle window open', function () {
        [$plain] = hdToken(hdStaff());
        $this->freezeTime();
        foreach (range(1, 6) as $i) {
            $this->travel(10)->hours();
            hdAs($plain)->getJson(HD . '/userInfo')->assertOk();
        }
    });

    it('expires a token after 7 days in any case, however active', function () {
        [$plain, $token] = hdToken(hdStaff());
        $this->freezeTime();
        // Active every 10 hours (so never idle) for just under 7 days...
        foreach (range(1, 16) as $i) {
            $this->travel(10)->hours();
            hdAs($plain)->getJson(HD . '/userInfo')->assertOk();
        }
        // ...and the next use, past the 7 days, is refused anyway.
        $this->travel(10)->hours();
        hdAs($plain)->getJson(HD . '/userInfo')->assertUnauthorized()->assertJsonPath('code', 'session_expired')->assertJsonPath('reason', 'absolute');
        expect(PersonalAccessToken::find($token->id))->toBeNull();
    });

    it('reads both limits from the configuration', function () {
        config(['admin.token_idle_hours' => 1, 'admin.token_max_days' => 2]);
        [$plain] = hdToken(hdStaff());
        $this->freezeTime();
        $this->travel(61)->minutes();
        hdAs($plain)->getJson(HD . '/userInfo')->assertUnauthorized()->assertJsonPath('reason', 'idle');
        [$plain2] = hdToken(hdStaff('support'));
        foreach (range(1, 3) as $i) {
            $this->travel(50)->minutes();
            hdAs($plain2)->getJson(HD . '/userInfo');
        }
        $this->travel(2)->days();
        hdAs($plain2)->getJson(HD . '/userInfo')->assertUnauthorized();
    });

    it('does not touch an impersonation token: its own 45 minutes rule, not the panel limits', function () {
        $staff = hdStaff();
        $target = User::factory()->create();
        [, $plain] = app(ImpersonationService::class)->start($staff, $target, 'Ayudar con las fotos', null, false);
        $token = PersonalAccessToken::findToken($plain);
        $this->freezeTime();

        // Old by the panel's standards (idle and absolute), but still inside nothing of ours: the admin area refuses it as before...
        $token->forceFill(['created_at' => now()->subDays(30), 'last_used_at' => now()->subDays(20)])->save();
        hdAs($plain)->getJson(HD . '/userInfo')->assertForbidden()->assertJsonMissingPath('code');
        // ...and it is neither revoked nor audited as an expired session.
        expect(PersonalAccessToken::find($token->id))->not->toBeNull()
            ->and(AdminAuditLog::where('action', 'account.session_expired')->count())->toBe(0);
        // In the app it keeps working until its own expiry.
        hdAs($plain)->getJson('/api/v1/app/auth/ready')->assertOk();
    });

    it('does not touch the app tokens of the organizers (client null): refused on the panel, kept and usable in the app', function () {
        $user = hdStaff();
        [$plain, $token] = hdToken($user, client: null);
        $token->forceFill(['created_at' => now()->subDays(10), 'last_used_at' => now()->subDays(9)])->save();

        hdAs($plain)->getJson(HD . '/userInfo')->assertUnauthorized()->assertJsonPath('code', 'session_expired');
        expect(PersonalAccessToken::find($token->id))->not->toBeNull();
        hdAs($plain)->getJson('/api/v1/app/auth/ready')->assertOk();
    });

    it('does not apply to a cookie session (no token to age)', function () {
        $this->actingAs(hdStaff())->getJson(HD . '/userInfo')->assertOk();
    });
});

describe('reauth: the step-up endpoint', function () {
    it('is closed to anonymous (401) and to normal app users (403)', function () {
        $this->postJson(HD . '/account/reauth', ['password' => HD_PW])->assertUnauthorized();
        $this->getJson(HD . '/account/reauth')->assertUnauthorized();
        $this->actingAs(User::factory()->create(['password' => HD_PW]))->postJson(HD . '/account/reauth', ['password' => HD_PW])->assertForbidden();
    });

    it('marks the token with the right password and reports until when', function () {
        [$plain, $token] = hdToken(hdStaff('support'));
        hdAs($plain)->getJson(HD . '/account/reauth')->assertOk()->assertJsonPath('data.reauthenticated', false);

        $this->freezeTime();
        $res = hdAs($plain)->postJson(HD . '/account/reauth', ['password' => HD_PW])->assertOk()
            ->assertJsonPath('data.reauthenticated', true)->assertJsonPath('data.minutes', 10)->assertJsonPath('data.needsCode', false);
        expect($res->json('data.until'))->toBe(now()->addMinutes(10)->toIso8601String());
        expect($token->refresh()->reauthenticated_at)->not->toBeNull();
        expect(AdminAuditLog::where('action', 'account.reauth')->count())->toBe(1);
        hdAs($plain)->getJson(HD . '/account/reauth')->assertJsonPath('data.reauthenticated', true);
    });

    it('a wrong password is a 422 on password, leaves the token as it was and is audited without the secret', function () {
        $user = hdStaff();
        [$plain, $token] = hdToken($user);
        hdAs($plain)->postJson(HD . '/account/reauth', ['password' => 'not-it-at-all'])->assertUnprocessable()->assertJsonValidationErrors('password');
        expect($token->refresh()->reauthenticated_at)->toBeNull();
        $row = AdminAuditLog::where('action', 'account.reauth_failed')->firstOrFail();
        expect($row->actor_id)->toBe($user->id)->and(json_encode($row->toArray()))->not->toContain('not-it-at-all');
        hdAs($plain)->postJson(HD . '/account/reauth', [])->assertUnprocessable()->assertJsonValidationErrors('password');
    });

    it('with two-factor on it also needs the TOTP code; a replayed or wrong one fails', function () {
        $user = hdStaff();
        $secret = hdEnrol($user);
        [$plain, $token] = hdToken($user);

        hdAs($plain)->getJson(HD . '/account/reauth')->assertJsonPath('data.needsCode', true);
        hdAs($plain)->postJson(HD . '/account/reauth', ['password' => HD_PW])->assertUnprocessable()->assertJsonValidationErrors('code');
        hdAs($plain)->postJson(HD . '/account/reauth', ['password' => HD_PW, 'code' => '000000'])->assertUnprocessable()->assertJsonValidationErrors('code');
        expect($token->refresh()->reauthenticated_at)->toBeNull();
        hdAs($plain)->postJson(HD . '/account/reauth', ['password' => 'wrong-password', 'code' => hdCode($secret)])->assertUnprocessable()->assertJsonValidationErrors('password');

        $code = hdCode($secret);
        hdAs($plain)->postJson(HD . '/account/reauth', ['password' => HD_PW, 'code' => $code])->assertOk()->assertJsonPath('data.reauthenticated', true);
        // The same code cannot be used twice, not even on another token.
        [$plain2] = hdToken($user);
        hdAs($plain2)->postJson(HD . '/account/reauth', ['password' => HD_PW, 'code' => $code])->assertUnprocessable()->assertJsonValidationErrors('code');
        expect(AdminAuditLog::where('action', 'account.reauth_failed')->where('after->factor', 'totp')->count())->toBeGreaterThanOrEqual(2);
    });

    it('locks after five failures for fifteen minutes, even for the right password', function () {
        $user = hdStaff();
        [$plain] = hdToken($user);
        foreach (range(1, 5) as $i) {
            hdAs($plain)->postJson(HD . '/account/reauth', ['password' => 'nope-nope-nope'])->assertUnprocessable();
            RateLimiter::clear('admin-account-sensitive'); // the route throttle is a different limit; the lockout is what is tested
        }
        $this->freezeTime();
        hdAs($plain)->postJson(HD . '/account/reauth', ['password' => HD_PW])->assertStatus(429);
        $this->travel(16)->minutes();
        hdAs($plain)->postJson(HD . '/account/reauth', ['password' => HD_PW])->assertOk();
    });

    it('is also throttled per route (10 a minute)', function () {
        [$plain] = hdToken(hdStaff());
        $last = null;
        foreach (range(1, 12) as $i) {
            $last = hdAs($plain)->postJson(HD . '/account/reauth', ['password' => HD_PW]);
        }
        $last->assertStatus(429);
    });

    it('a cookie session cannot reauthenticate (nowhere to remember it): 409 token_required', function () {
        $this->actingAs(hdStaff())->postJson(HD . '/account/reauth', ['password' => HD_PW])->assertStatus(409)->assertJsonPath('code', 'token_required');
    });

    it('never returns the password, secrets or the token', function () {
        $user = hdStaff();
        $secret = hdEnrol($user);
        [$plain] = hdToken($user);
        $body = hdAs($plain)->postJson(HD . '/account/reauth', ['password' => HD_PW, 'code' => hdCode($secret)])->assertOk()->getContent();
        foreach ([HD_PW, $secret, $plain, 'secret', 'recovery', 'password'] as $needle) {
            expect($body)->not->toContain($needle);
        }
    });
});

describe('reauth: protected actions', function () {
    // Method, path, body: each must answer 403 reauth_required without a recent re-authentication, for a superadmin that has the permission.
    $protected = function () {
        $other = User::factory()->create();
        $event = Events::factory()->create(['event_type_id' => EventType::where('slug', 'quinceanera')->value('id'), 'event_plan_id' => EventPlan::where('slug', 'premium')->value('id')]);
        $purchase = EventPurchase::create([
            'event_id' => $event->id, 'user_id' => $other->id, 'event_plan_id' => EventPlan::where('slug', 'premium')->value('id'),
            'gateway' => 'stripe', 'list_price_cents' => 9900, 'amount_cents' => 9900, 'status' => 'paid', 'paid_at' => now(),
        ]);
        $coupon = Coupon::create(['code' => 'HD1', 'kind' => 'fixed_price', 'value' => 5900]);

        return [
            'refund' => ['POST', "/payments/{$purchase->id}/refund", ['reason' => 'Pidió el reembolso']],
            'price' => ['PUT', '/payments/price', ['priceCents' => 12900, 'reason' => 'Nuevo precio']],
            'coupon create' => ['POST', '/payments/coupons', ['code' => 'X', 'reason' => 'Promo']],
            'coupon edit' => ['PATCH', "/payments/coupons/{$coupon->id}", ['reason' => 'Promo']],
            'user delete' => ['DELETE', "/users/{$other->id}", ['reason' => 'Pidió borrarla']],
            'team add' => ['POST', '/team', ['email' => 'nuevo@example.com', 'role' => 'support', 'name' => 'N', 'reason' => 'Refuerzo']],
            'team role' => ['PATCH', "/team/{$other->id}", ['role' => 'support', 'reason' => 'Cambio']],
            'team remove' => ['DELETE', "/team/{$other->id}", ['reason' => 'Se fue']],
            'team 2fa reset' => ['POST', "/team/{$other->id}/two-factor/reset", ['reason' => 'Perdió el teléfono']],
        ];
    };

    it('answers 403 reauth_required (with the window) to a superadmin who has not reauthenticated', function () use ($protected) {
        [$plain] = hdToken(hdStaff());
        foreach ($protected() as $name => [$method, $path, $body]) {
            hdAs($plain)->json($method, HD . $path, $body)->assertForbidden()->assertJsonPath('code', 'reauth_required')->assertJsonPath('reauthMinutes', 10);
        }
    });

    it('answers 401 to anonymous and a plain 403 (no reauth hint) to a role without the permission', function () use ($protected) {
        [$plain] = hdToken(hdStaff('support'), reauth: true);
        $routes = $protected();
        // Anonymous first: a header set by hdAs() stays on the test instance.
        foreach ($routes as [$method, $path, $body]) {
            $this->json($method, HD . $path, $body)->assertUnauthorized();
        }
        foreach ($routes as [$method, $path, $body]) {
            hdAs($plain)->json($method, HD . $path, $body)->assertForbidden()->assertJsonMissingPath('code');
        }
    });

    it('lets the action through while the reauthentication is valid, and refuses it again after it expires', function () {
        [$plain] = hdToken(hdStaff());
        $this->freezeTime();
        hdAs($plain)->postJson(HD . '/account/reauth', ['password' => HD_PW])->assertOk();

        $this->travel(9)->minutes();
        // A body that fails validation proves the request got past the middleware (422, not 403).
        hdAs($plain)->postJson(HD . '/team', ['email' => 'no', 'role' => 'x'])->assertUnprocessable();
        hdAs($plain)->putJson(HD . '/payments/price', [])->assertStatus(422);

        $this->travel(2)->minutes();
        hdAs($plain)->postJson(HD . '/team', ['email' => 'no', 'role' => 'x'])->assertForbidden()->assertJsonPath('code', 'reauth_required');
    });

    it('is per token: another session of the same person is not reauthenticated', function () {
        $user = hdStaff();
        [$a] = hdToken($user, reauth: true);
        [$b] = hdToken($user);
        hdAs($a)->postJson(HD . '/team', ['email' => 'no'])->assertUnprocessable();
        hdAs($b)->postJson(HD . '/team', ['email' => 'no'])->assertForbidden()->assertJsonPath('code', 'reauth_required');
    });

    it('a cookie session never passes', function () {
        $this->actingAs(hdStaff())->postJson(HD . '/team', ['email' => 'no'])->assertForbidden()->assertJsonPath('code', 'reauth_required');
    });

    it('does not ask for it on reads', function () {
        [$plain] = hdToken(hdStaff());
        foreach (['/team', '/payments', '/payments/coupons', '/revenue/purchases', '/revenue/pricing', '/users', '/audit', '/dashboard'] as $path) {
            hdAs($plain)->getJson(HD . $path)->assertOk();
        }
    });

    it('an impersonation token never gets near these routes (403 from the admin gate, not reauth)', function () {
        $staff = hdStaff();
        $target = User::factory()->create();
        [, $plain] = app(ImpersonationService::class)->start($staff, $target, 'Ayudar con las fotos', null, false);
        $token = PersonalAccessToken::findToken($plain);
        $token->forceFill(['reauthenticated_at' => now()])->save();
        hdAs($plain)->postJson(HD . '/team', ['email' => 'no'])->assertForbidden()->assertJsonMissingPath('code');
        hdAs($plain)->postJson(HD . '/account/reauth', ['password' => 'x'])->assertForbidden();
    });
});

describe('reset the two-factor of another team member', function () {
    function hdReset($actorPlain, User $target, array $body = ['reason' => 'Perdió el teléfono y los códigos'])
    {
        return hdAs($actorPlain)->postJson(HD . "/team/{$target->id}/two-factor/reset", $body);
    }

    it('turns it off, closes her sessions, mails her and audits it with the reason', function () {
        $actor = hdStaff();
        [$plain] = hdToken($actor, reauth: true);
        $target = hdStaff('support', ['name' => 'Lucía Soporte']);
        $secret = hdEnrol($target);
        [, $t1] = hdToken($target);
        hdToken($target, client: null);

        $res = hdReset($plain, $target)->assertOk()->assertJsonPath('data.twoFactorEnabled', false)->assertJsonPath('data.sessionsClosed', 2);

        expect(AdminTwoFactor::where('user_id', $target->id)->count())->toBe(0)
            ->and($target->tokens()->count())->toBe(0)
            ->and($actor->tokens()->count())->toBe(1);
        Mail::assertSent(AdminSecurityNoticeMail::class, fn ($m) => $m->hasTo($target->email) && $m->type === 'two_factor_reset_by_admin' && $m->details['actor'] === $actor->name);
        $row = AdminAuditLog::where('action', 'account.two_factor_reset_by_admin')->firstOrFail();
        expect($row->actor_id)->toBe($actor->id)->and($row->user_id)->toBe($target->id)->and($row->reason)->toBe('Perdió el teléfono y los códigos')
            ->and($row->after['sessionsClosed'])->toBe(2);
        expect($res->getContent())->not->toContain($secret)->not->toContain('recovery');
        expect(json_encode($row->toArray()))->not->toContain($secret);
    });

    it('requires a reason of at least five characters', function () {
        [$plain] = hdToken(hdStaff(), reauth: true);
        $target = hdStaff('support');
        hdEnrol($target);
        hdReset($plain, $target, [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        hdReset($plain, $target, ['reason' => 'abc'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        expect($target->fresh()->hasAdminTwoFactor())->toBeTrue();
    });

    it('is refused on your own account (422 cannot_change_self)', function () {
        $actor = hdStaff();
        hdEnrol($actor);
        [$plain] = hdToken($actor, reauth: true);
        hdReset($plain, $actor)->assertUnprocessable()->assertJsonValidationErrors('user');
        expect($actor->fresh()->hasAdminTwoFactor())->toBeTrue();
    });

    it('is for the team manager only: support gets 403, a normal user 403, anonymous 401', function () {
        $target = hdStaff('support');
        hdEnrol($target);
        $this->postJson(HD . "/team/{$target->id}/two-factor/reset", ['reason' => 'Perdió el teléfono'])->assertUnauthorized();
        [$support] = hdToken(hdStaff('support'), reauth: true);
        hdReset($support, $target)->assertForbidden();
        $this->flushHeaders();
        $this->actingAs(User::factory()->create())->postJson(HD . "/team/{$target->id}/two-factor/reset", ['reason' => 'Perdió el teléfono'])->assertForbidden();
        expect($target->fresh()->hasAdminTwoFactor())->toBeTrue();
    });

    it('needs a recent reauthentication', function () {
        [$plain] = hdToken(hdStaff());
        $target = hdStaff('support');
        hdEnrol($target);
        hdReset($plain, $target)->assertForbidden()->assertJsonPath('code', 'reauth_required');
        expect($target->fresh()->hasAdminTwoFactor())->toBeTrue();
    });

    it('404 for someone who is not on the team, 409 when she has no two-factor on', function () {
        [$plain] = hdToken(hdStaff(), reauth: true);
        hdReset($plain, User::factory()->create())->assertNotFound();
        hdReset($plain, hdStaff('support'))->assertStatus(409)->assertJsonPath('code', 'two_factor_not_enabled');
        Mail::assertNothingSent();
    });

    it('resetting another SUPERADMIN needs the actor to have two-factor on herself', function () {
        $target = hdStaff('superadmin');
        hdEnrol($target);

        $without = hdStaff('superadmin');
        [$plain] = hdToken($without, reauth: true);
        hdReset($plain, $target)->assertForbidden()->assertJsonPath('code', 'two_factor_required_to_reset');
        expect($target->fresh()->hasAdminTwoFactor())->toBeTrue();
        Mail::assertNothingSent();

        $with = hdStaff('superadmin');
        hdEnrol($with);
        [$plain2] = hdToken($with, reauth: true);
        hdReset($plain2, $target)->assertOk();
        expect($target->fresh()->hasAdminTwoFactor())->toBeFalse();
    });

    it('does not touch the impersonation sessions of anyone (their tokens are theirs, 45 minutes)', function () {
        $actor = hdStaff();
        [$plain] = hdToken($actor, reauth: true);
        $target = hdStaff('support');
        hdEnrol($target);
        $user = User::factory()->create();
        [$session, $imp] = app(ImpersonationService::class)->start(hdStaff('support'), $user, 'Ayudar con las fotos', null, false);

        hdReset($plain, $target)->assertOk();

        $token = PersonalAccessToken::findToken($imp);
        expect($token)->not->toBeNull()->and($session->fresh()->ended_at)->toBeNull();
        hdAs($imp)->getJson('/api/v1/app/auth/ready')->assertOk();
    });

    it('the team list tells who has two-factor on, and nothing secret', function () {
        [$plain] = hdToken(hdStaff());
        $with = hdStaff('support', ['name' => 'Con Dos Pasos']);
        $secret = hdEnrol($with);
        $res = hdAs($plain)->getJson(HD . '/team')->assertOk();
        $rows = collect($res->json('data'));
        expect($rows->firstWhere('id', $with->id)['twoFactorEnabled'])->toBeTrue()
            ->and($rows->where('twoFactorEnabled', false)->count())->toBe(1);
        expect($res->getContent())->not->toContain($secret);
    });

    it('the mail renders in both languages', function () {
        foreach (['es', 'en'] as $lang) {
            $user = hdStaff('support', ['name' => 'Ana']);
            $user->preferences()->create(['language' => $lang, 'timezone' => 'UTC']);
            $html = (new AdminSecurityNoticeMail($user->fresh(), 'two_factor_reset_by_admin', ['actor' => 'Marisol']))->render();
            expect($html)->toContain('Marisol');
        }
    });
});
