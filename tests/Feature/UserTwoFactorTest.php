<?php

use App\Http\Services\AppServices\UserTwoFactorService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

const TF = '/api/v1/app/user/2fa';

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    RateLimiter::clear('app-2fa:1');
});

function tfUser(array $attrs = []): User
{
    return User::factory()->create(['password' => 'secret-pass-1'] + $attrs);
}

function tfActAs(User $user): void
{
    Sanctum::actingAs($user);
}

function totp(string $secret, int $offset = 0): string
{
    return (new Google2FA)->oathTotp($secret, (new Google2FA)->getTimestamp() + $offset);
}

/** Runs setup + enable; returns [secret, codes]. */
function tfEnable($t, User $user): array
{
    tfActAs($user);
    $secret = $t->getJson(TF . '/setup')->assertOk()->json('secret');
    $codes = $t->postJson(TF . '/enable', ['code' => totp($secret), 'currentPassword' => 'secret-pass-1'])->assertOk()->json('recovery_codes');

    return [$secret, $codes];
}

function rawRow(User $u): object
{
    return DB::table('users')->where('id', $u->id)->first();
}

describe('before and after enrolment', function () {
    it('keeps the secret encrypted and the codes hashed in the database', function () {
        $user = tfUser();
        [$secret, $codes] = tfEnable($this, $user);

        $row = rawRow($user);
        expect($row->two_factor_secret)->not->toBe($secret)
            ->and(Crypt::decryptString($row->two_factor_secret))->toBe($secret)
            ->and($codes)->toHaveCount(8);
        foreach ($codes as $code) {
            expect($row->two_factor_recovery_codes)->not->toContain($code)->not->toContain(str_replace('-', '', $code));
        }
        expect(json_decode($row->two_factor_recovery_codes, true)[0])->toHaveKeys(['hash', 'used_at'])
            ->and($row->two_factor_confirmed_at)->not->toBeNull();
    });

    it('does not show the secret again once active, nor allow a new setup', function () {
        $user = tfUser();
        tfEnable($this, $user);

        $this->getJson(TF . '/setup')->assertStatus(409);
        $this->getJson(TF . '/status')->assertOk()->assertJson(['enabled' => true, 'verified' => true, 'setupDone' => true, 'recoveryCodesRemaining' => 8]);
    });

    it('reports a pending setup as not enabled', function () {
        tfActAs(tfUser());
        $this->getJson(TF . '/setup')->assertOk()->assertJsonStructure(['qr_code', 'secret'])->assertJsonMissingPath('recovery_codes');
        $this->getJson(TF . '/status')->assertOk()->assertJson(['enabled' => false, 'setupDone' => true, 'recoveryCodesRemaining' => 0]);
    });
});

describe('enable', function () {
    it('needs the current password and a valid code', function () {
        $user = tfUser();
        tfActAs($user);
        $secret = $this->getJson(TF . '/setup')->json('secret');

        $this->postJson(TF . '/enable', ['code' => totp($secret)])->assertStatus(422);
        $this->postJson(TF . '/enable', ['code' => totp($secret), 'currentPassword' => 'wrong'])->assertStatus(422);
        $this->postJson(TF . '/enable', ['code' => '000000', 'currentPassword' => 'secret-pass-1'])->assertStatus(422);
        expect($user->fresh()->two_factor_confirmed_at)->toBeNull();

        $this->postJson(TF . '/enable', ['code' => totp($secret), 'currentPassword' => 'secret-pass-1'])->assertOk()->assertJsonCount(8, 'recovery_codes');
    });

    it('fails without a prior setup', function () {
        tfActAs(tfUser());
        $this->postJson(TF . '/enable', ['code' => '123456', 'currentPassword' => 'secret-pass-1'])->assertStatus(422);
    });

    it('is refused when already active', function () {
        [$secret] = tfEnable($this, tfUser());
        $this->postJson(TF . '/enable', ['code' => totp($secret), 'currentPassword' => 'secret-pass-1'])->assertStatus(409);
    });
});

describe('verify', function () {
    it('accepts a TOTP code only once (no replay of the same time step)', function () {
        $user = tfUser();
        [$secret] = tfEnable($this, $user);
        // enable consumed the current step; a later step is fine, the same or an older one is not.
        $future = totp($secret, 1);
        $this->postJson(TF . '/verify', ['code' => $future])->assertOk()->assertJson(['valid' => true]);
        $this->postJson(TF . '/verify', ['code' => $future])->assertStatus(422);
        $this->postJson(TF . '/verify', ['code' => totp($secret)])->assertStatus(422);
    });

    it('rejects the code used to enable it', function () {
        $user = tfUser();
        tfActAs($user);
        $secret = $this->getJson(TF . '/setup')->json('secret');
        $code = totp($secret);
        $this->postJson(TF . '/enable', ['code' => $code, 'currentPassword' => 'secret-pass-1'])->assertOk();
        $this->postJson(TF . '/verify', ['code' => $code])->assertStatus(422);
    });

    it('lets each recovery code in exactly once, with any case or dash', function () {
        [$secret, $codes] = tfEnable($this, tfUser());

        $this->postJson(TF . '/verify', ['code' => strtolower(str_replace('-', ' ', $codes[0]))])->assertOk();
        $this->postJson(TF . '/verify', ['code' => $codes[0]])->assertStatus(422);
        $this->postJson(TF . '/verify', ['code' => $codes[1]])->assertOk();
        $this->getJson(TF . '/status')->assertJson(['recoveryCodesRemaining' => 6]);
    });

    it('refuses garbage and an inactive account', function () {
        tfActAs(tfUser());
        $this->postJson(TF . '/verify', ['code' => '123456'])->assertStatus(409);
        $this->postJson(TF . '/verify', [])->assertStatus(422);
    });

    it('locks a user out of guessing after too many wrong codes, and only her', function () {
        $user = tfUser();
        $other = tfUser();
        tfEnable($this, $user);
        tfActAs($user);
        RateLimiter::clear("app-2fa:{$user->id}");

        for ($i = 0; $i < 6; $i++) {
            $this->postJson(TF . '/verify', ['code' => '000000'])->assertStatus(422);
        }
        $this->postJson(TF . '/verify', ['code' => '000000'])->assertStatus(429);

        tfActAs($other);
        $this->postJson(TF . '/verify', ['code' => '000000'])->assertStatus(409);
    });
});

describe('disable and regenerate', function () {
    it('disable needs the current password and clears everything', function () {
        $user = tfUser();
        tfEnable($this, $user);

        $this->postJson(TF . '/disable')->assertStatus(422);
        $this->postJson(TF . '/disable', ['currentPassword' => 'nope'])->assertStatus(422);
        expect($user->fresh()->two_factor_confirmed_at)->not->toBeNull();

        $this->postJson(TF . '/disable', ['currentPassword' => 'secret-pass-1'])->assertOk();
        $row = rawRow($user);
        expect($row->two_factor_secret)->toBeNull()->and($row->two_factor_recovery_codes)->toBeNull()
            ->and($row->two_factor_confirmed_at)->toBeNull()->and($row->two_factor_last_used_step)->toBeNull();
    });

    it('regenerate needs the password, returns new codes once and kills the old ones', function () {
        [, $old] = tfEnable($this, tfUser());

        $this->postJson(TF . '/recovery-codes')->assertStatus(422);
        $this->postJson(TF . '/recovery-codes', ['currentPassword' => 'bad'])->assertStatus(422);
        $new = $this->postJson(TF . '/recovery-codes', ['currentPassword' => 'secret-pass-1'])->assertOk()->json('codes');

        expect($new)->toHaveCount(8)->and(array_intersect($old, $new))->toBeEmpty();
        $this->postJson(TF . '/verify', ['code' => $old[0]])->assertStatus(422);
        $this->postJson(TF . '/verify', ['code' => $new[0]])->assertOk();
    });

    it('regenerate is refused when not active', function () {
        tfActAs(tfUser());
        $this->postJson(TF . '/recovery-codes', ['currentPassword' => 'secret-pass-1'])->assertStatus(409);
    });

    it('wrong passwords count against the same throttle', function () {
        $user = tfUser();
        tfEnable($this, $user);
        RateLimiter::clear("app-2fa:{$user->id}");
        for ($i = 0; $i < 6; $i++) {
            $this->postJson(TF . '/disable', ['currentPassword' => 'nope'])->assertStatus(422);
        }
        $this->postJson(TF . '/disable', ['currentPassword' => 'secret-pass-1'])->assertStatus(429);
        expect($user->fresh()->two_factor_confirmed_at)->not->toBeNull();
    });
});

describe('nothing leaks', function () {
    it('never returns the secret or the codes outside the one-time responses', function () {
        $user = tfUser();
        [$secret, $codes] = tfEnable($this, $user);

        $responses = [
            $this->getJson(TF . '/status'),
            $this->getJson(TF . '/recovery-codes'),
            $this->getJson('/api/v1/app/user'),
            $this->postJson(TF . '/verify', ['code' => '000000']),
            $this->postJson(TF . '/disable', ['currentPassword' => 'bad']),
        ];
        foreach ($responses as $r) {
            $body = $r->getContent();
            expect($body)->not->toContain($secret)->not->toContain('two_factor_secret')->not->toContain('two_factor_recovery_codes');
            foreach ($codes as $code) {
                expect($body)->not->toContain($code);
            }
        }
        $this->getJson(TF . '/recovery-codes')->assertOk()->assertExactJson(['remaining' => 8]);
    });

    it('does not serialize the secret fields of the model', function () {
        $user = tfUser();
        tfEnable($this, $user);
        $array = $user->fresh()->toArray();
        expect($array)->not->toHaveKeys(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_last_used_step']);
    });

    it('writes neither secret nor codes in the logs', function () {
        Log::spy();
        $user = tfUser();
        [$secret, $codes] = tfEnable($this, $user);
        $this->postJson(TF . '/verify', ['code' => $codes[0]]);
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('info', fn ($m, $ctx = []) => str_contains(json_encode([$m, $ctx]), $secret));
    });
});

describe('isolation and impersonation', function () {
    it("never touches another user's enrolment", function () {
        $a = tfUser();
        $b = tfUser();
        [$secretA] = tfEnable($this, $a);
        tfActAs($b);

        $this->getJson(TF . '/status')->assertJson(['enabled' => false, 'setupDone' => false]);
        $this->postJson(TF . '/disable', ['currentPassword' => 'secret-pass-1'])->assertOk();
        $this->postJson(TF . '/verify', ['code' => totp($secretA, 1)])->assertStatus(409);
        expect($a->fresh()->two_factor_confirmed_at)->not->toBeNull()
            ->and(Crypt::decryptString(rawRow($a)->two_factor_secret))->toBe($secretA);
    });

    it('requires authentication', function () {
        $this->getJson(TF . '/status')->assertUnauthorized();
        $this->postJson(TF . '/disable', ['currentPassword' => 'x'])->assertUnauthorized();
    });

    it('keeps every two-factor route blocked during impersonation', function () {
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/app/user/2fa/'))
            ->flatMap(fn ($r) => collect($r->methods())->reject(fn ($m) => $m === 'HEAD')->map(fn ($m) => "$m {$r->uri()}"));
        expect($routes)->not->toBeEmpty();
        foreach ($routes as $route) {
            expect(\App\Support\ImpersonationRules::BLOCKED)->toContain($route);
        }
    });
});

describe('legacy rows', function () {
    function runDataMigration(string $direction = 'up'): void
    {
        $migration = require base_path('database/migrations/2027_01_20_000002_secure_existing_user_two_factor.php');
        $migration->{$direction}();
    }

    it('encrypts plain secrets and hashes plain codes, keeping the old codes valid', function () {
        $user = tfUser();
        $secret = (new Google2FA)->generateSecretKey();
        DB::table('users')->where('id', $user->id)->update([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => json_encode(['AbCdEfGh12', 'zzzzzzzzzz']),
            'two_factor_confirmed_at' => now(),
        ]);

        runDataMigration();

        $row = rawRow($user);
        expect($row->two_factor_secret)->not->toBe($secret)
            ->and(Crypt::decryptString($row->two_factor_secret))->toBe($secret)
            ->and($row->two_factor_recovery_codes)->not->toContain('AbCdEfGh12')->not->toContain('zzzzzzzzzz');

        tfActAs($user->fresh());
        $this->getJson(TF . '/status')->assertJson(['enabled' => true, 'recoveryCodesRemaining' => 2]);
        $this->postJson(TF . '/verify', ['code' => 'AbCdEfGh12'])->assertOk();
        $this->postJson(TF . '/verify', ['code' => 'AbCdEfGh12'])->assertStatus(422);
        $this->postJson(TF . '/verify', ['code' => totp($secret)])->assertOk();
    });

    it('is idempotent', function () {
        $user = tfUser();
        $secret = (new Google2FA)->generateSecretKey();
        DB::table('users')->where('id', $user->id)->update(['two_factor_secret' => $secret, 'two_factor_recovery_codes' => json_encode(['ABCDE12345'])]);

        runDataMigration();
        $first = rawRow($user);
        runDataMigration();
        $second = rawRow($user);

        expect($second->two_factor_secret)->toBe($first->two_factor_secret)
            ->and($second->two_factor_recovery_codes)->toBe($first->two_factor_recovery_codes)
            ->and(Crypt::decryptString($second->two_factor_secret))->toBe($secret);
    });

    it('leaves users without two-factor untouched and does not touch rows enrolled after the fix', function () {
        $plain = tfUser();
        $enrolled = tfUser();
        [$secret, $codes] = tfEnable($this, $enrolled);
        $before = rawRow($enrolled);

        runDataMigration();

        expect(rawRow($plain)->two_factor_secret)->toBeNull()
            ->and(rawRow($enrolled)->two_factor_secret)->toBe($before->two_factor_secret)
            ->and(rawRow($enrolled)->two_factor_recovery_codes)->toBe($before->two_factor_recovery_codes);
    });

    it('rolls back secrets to plain text and clears the unrecoverable codes', function () {
        $user = tfUser();
        $secret = (new Google2FA)->generateSecretKey();
        DB::table('users')->where('id', $user->id)->update(['two_factor_secret' => $secret, 'two_factor_recovery_codes' => json_encode(['ABCDE12345'])]);

        runDataMigration();
        runDataMigration('down');

        $row = rawRow($user);
        expect($row->two_factor_secret)->toBe($secret)->and($row->two_factor_recovery_codes)->toBeNull();
    });
});

it('hashes recovery codes the same way the migration does', function () {
    expect(UserTwoFactorService::hash('abcde-12345'))->toBe(UserTwoFactorService::hash('ABCDE12345'));
});
