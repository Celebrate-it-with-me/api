<?php

use App\Http\Services\Admin\System\TwoFactorEnforcement;
use App\Models\AdminAuditLog;
use App\Models\AdminTwoFactor;
use App\Models\User;
use App\Support\AdminPermissions;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

const SF_URL = '/api/v1/admin/system/security';
const SF_REASON = 'Política de seguridad del equipo';

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    User::role(AdminPermissions::STAFF_ROLES)->get()->each(fn (User $u) => $u->syncRoles([]));
    Cache::forget(TwoFactorEnforcement::CACHE_KEY);
    config(['admin.require_two_factor_superadmin' => false]);
});

function sfStaff(string $role = AdminPermissions::SUPERADMIN, bool $enrolled = false, array $attrs = []): User
{
    $u = User::factory()->create($attrs);
    $u->assignRole($role);
    if ($enrolled) {
        AdminTwoFactor::create(['user_id' => $u->id, 'secret' => (new Google2FA)->generateSecretKey(), 'confirmed_at' => now(), 'recovery_codes' => []]);
        $u->unsetRelation('adminTwoFactor');
    }

    return $u;
}

/** A real panel token (the cookie-less kind that remembers a re-authentication). */
function sfAs(User $user, bool $reauth = true): object
{
    $new = $user->createToken('Chrome 130', ['*']);
    $new->accessToken->forceFill(['client' => 'admin', 'reauthenticated_at' => $reauth ? now() : null])->save();
    app('auth')->forgetGuards();

    return test()->withHeader('Authorization', 'Bearer ' . $new->plainTextToken);
}

function sfSwitch(bool $on): void
{
    app(TwoFactorEnforcement::class)->set($on, null);
}

describe('permissions', function () {
    it('is superadmin only: 401 anonymous, 403 organizer and support, 200 superadmin', function () {
        $this->getJson(SF_URL)->assertUnauthorized();
        $this->putJson(SF_URL, ['enabled' => false, 'reason' => SF_REASON])->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson(SF_URL)->assertForbidden();
        $this->actingAs(User::factory()->create())->putJson(SF_URL, ['enabled' => false, 'reason' => SF_REASON])->assertForbidden();
        $this->actingAs(sfStaff(AdminPermissions::SUPPORT))->getJson(SF_URL)->assertForbidden();
        sfAs(sfStaff(AdminPermissions::SUPPORT))->putJson(SF_URL, ['enabled' => false, 'reason' => SF_REASON])->assertForbidden();
        $this->actingAs(sfStaff())->getJson(SF_URL)->assertOk();

        expect(AdminPermissions::for(AdminPermissions::SUPPORT))->not->toContain('admin.security.manage')
            ->and(AdminPermissions::for(AdminPermissions::SUPERADMIN))->toContain('admin.security.manage');
    });

    it('creates the permission and gives it to the existing superadmin role through the migration', function () {
        DB::table('role_has_permissions')->whereIn('permission_id', DB::table('permissions')->where('name', 'admin.security.manage')->pluck('id'))->delete();
        DB::table('permissions')->where('name', 'admin.security.manage')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        (require database_path('migrations/2027_02_01_000001_admin_security_manage_permission.php'))->up();

        expect(sfStaff()->can('admin.security.manage'))->toBeTrue()
            ->and(sfStaff(AdminPermissions::SUPPORT)->can('admin.security.manage'))->toBeFalse();
    });
});

describe('status', function () {
    it('is off by default and counts who has two-factor, listing who does not', function () {
        $me = sfStaff(enrolled: true);
        $ana = sfStaff(AdminPermissions::SUPPORT, false, ['name' => 'Ana Soporte', 'email' => 'ana@cwm.test']);
        sfStaff(AdminPermissions::SUPPORT, true);
        // Enrolment started but never confirmed: does not count.
        $pending = sfStaff(AdminPermissions::SUPPORT, false, ['name' => 'Berta Pendiente']);
        AdminTwoFactor::create(['user_id' => $pending->id, 'secret' => (new Google2FA)->generateSecretKey(), 'confirmed_at' => null]);
        User::factory()->create(); // an organizer: not staff

        $res = $this->actingAs($me)->getJson(SF_URL)->assertOk()
            ->assertJsonPath('enabled', false)->assertJsonPath('enrolled', 2)->assertJsonPath('total', 4)->assertJsonPath('envForced', false)
            ->assertJsonCount(2, 'notEnrolled');

        expect(collect($res->json('notEnrolled'))->pluck('id')->all())->toBe([$ana->id, $pending->id])
            ->and($res->json('notEnrolled.0'))->toBe(['id' => $ana->id, 'name' => 'Ana Soporte', 'email' => 'ana@cwm.test']);
    });

    it('caps the list at 50 people but not the count, and never leaks secrets', function () {
        $me = sfStaff(enrolled: true);
        User::factory()->count(55)->create()->each(fn (User $u) => $u->assignRole(AdminPermissions::SUPPORT));

        $res = $this->actingAs($me)->getJson(SF_URL)->assertOk()->assertJsonCount(50, 'notEnrolled')->assertJsonPath('total', 56)->assertJsonPath('enrolled', 1);
        expect(array_keys($res->json('notEnrolled.0')))->toBe(['id', 'name', 'email']);
    });

    it('does not run one query per person', function () {
        $me = sfStaff(enrolled: true);
        User::factory()->count(10)->create()->each(fn (User $u) => $u->assignRole(AdminPermissions::SUPPORT));
        $this->actingAs($me)->getJson(SF_URL)->assertOk(); // warm-up: permission and role caches
        DB::enableQueryLog();
        $this->getJson(SF_URL)->assertOk();
        $few = count(DB::getQueryLog());
        User::factory()->count(10)->create()->each(fn (User $u) => $u->assignRole(AdminPermissions::SUPPORT));
        DB::flushQueryLog();
        $this->getJson(SF_URL)->assertOk();

        expect(count(DB::getQueryLog()))->toBe($few);
    });

    it('reports when the environment variable forces superadmins', function () {
        config(['admin.require_two_factor_superadmin' => true]);
        $this->actingAs(sfStaff(enrolled: true))->getJson(SF_URL)->assertOk()->assertJsonPath('envForced', true)->assertJsonPath('enabled', false);
    });
});

describe('changing the switch', function () {
    it('requires a reason of at least five characters, and a boolean', function () {
        $me = sfStaff(enrolled: true);
        sfAs($me)->putJson(SF_URL, ['enabled' => true])->assertUnprocessable()->assertJsonValidationErrors('reason');
        sfAs($me)->putJson(SF_URL, ['enabled' => true, 'reason' => 'abc'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        sfAs($me)->putJson(SF_URL, ['reason' => SF_REASON])->assertUnprocessable()->assertJsonValidationErrors('enabled');
        sfAs($me)->putJson(SF_URL, ['enabled' => 'maybe', 'reason' => SF_REASON])->assertUnprocessable()->assertJsonValidationErrors('enabled');

        expect(app(TwoFactorEnforcement::class)->enabled())->toBeFalse();
    });

    it('needs a recent re-authentication: 403 reauth_required, nothing changes', function () {
        $me = sfStaff(enrolled: true);

        sfAs($me, reauth: false)->putJson(SF_URL, ['enabled' => true, 'reason' => SF_REASON])->assertForbidden()->assertJsonPath('code', 'reauth_required');
        $this->actingAs($me)->putJson(SF_URL, ['enabled' => true, 'reason' => SF_REASON])->assertForbidden()->assertJsonPath('code', 'reauth_required');

        expect(app(TwoFactorEnforcement::class)->enabled())->toBeFalse()->and(AdminAuditLog::count())->toBe(0);
    });

    it('turns it on and off and audits who, why, before and after, with counts', function () {
        $me = sfStaff(enrolled: true);
        sfStaff(AdminPermissions::SUPPORT);

        sfAs($me)->putJson(SF_URL, ['enabled' => true, 'reason' => SF_REASON])->assertOk()
            ->assertJsonPath('enabled', true)->assertJsonPath('enrolled', 1)->assertJsonPath('total', 2)->assertJsonCount(1, 'notEnrolled');
        sfAs($me)->getJson(SF_URL)->assertJsonPath('enabled', true);
        $on = AdminAuditLog::where('action', 'system.require_two_factor_on')->firstOrFail();
        expect($on->actor_id)->toBe($me->id)->and($on->reason)->toBe(SF_REASON)
            ->and($on->before)->toBe(['enabled' => false, 'enrolled' => 1, 'total' => 2])
            ->and($on->after)->toBe(['enabled' => true, 'enrolled' => 1, 'total' => 2, 'notEnrolled' => 1]);

        sfAs($me)->putJson(SF_URL, ['enabled' => false, 'reason' => 'Ya no hace falta']);
        sfAs($me)->getJson(SF_URL)->assertJsonPath('enabled', false);
        $off = AdminAuditLog::where('action', 'system.require_two_factor_off')->firstOrFail();
        expect($off->before['enabled'])->toBeTrue()->and($off->after['enabled'])->toBeFalse();
        expect(DB::table('system_flags')->where('key', TwoFactorEnforcement::KEY)->value('updated_by'))->toBe($me->id);
    });

    it('refuses to turn it on if you have no two-factor of your own: 422 own_two_factor_required', function () {
        $me = sfStaff();
        // Enrolment started but not confirmed is the same as none.
        AdminTwoFactor::create(['user_id' => $me->id, 'secret' => (new Google2FA)->generateSecretKey(), 'confirmed_at' => null]);

        sfAs($me)->putJson(SF_URL, ['enabled' => true, 'reason' => SF_REASON])->assertUnprocessable()->assertJsonPath('code', 'own_two_factor_required');

        expect(app(TwoFactorEnforcement::class)->enabled())->toBeFalse()
            ->and(AdminAuditLog::where('action', 'like', 'system.require_two_factor%')->count())->toBe(0);
    });

    it('does not apply the own-two-factor check to turning it off', function () {
        $me = sfStaff();

        sfAs($me)->putJson(SF_URL, ['enabled' => false, 'reason' => 'Dejarlo como estaba'])->assertOk()->assertJsonPath('enabled', false);
    });

    it('cannot be reached by someone without two-factor once it is on (the middleware stops her first): the console command is the way out', function () {
        sfSwitch(true);
        $me = sfStaff();

        sfAs($me)->putJson(SF_URL, ['enabled' => false, 'reason' => 'Nos bloqueamos solas'])->assertForbidden()->assertJsonPath('code', 'two_factor_required');
        expect(app(TwoFactorEnforcement::class)->enabled())->toBeTrue();
    });
});

describe('enforcement', function () {
    it('changes nothing while the switch is off', function () {
        $super = sfStaff();
        $support = sfStaff(AdminPermissions::SUPPORT);

        $this->actingAs($super)->getJson('/api/v1/admin/dashboard')->assertOk();
        $this->actingAs($support)->getJson('/api/v1/admin/dashboard')->assertOk();
        $this->actingAs($support)->getJson('/api/v1/admin/userInfo')->assertJsonPath('data.security.twoFactorSetupRequired', false);
    });

    it('blocks every staff member without two-factor (superadmin and support) except in the account pages and userInfo', function () {
        sfSwitch(true);

        foreach ([AdminPermissions::SUPERADMIN, AdminPermissions::SUPPORT] as $role) {
            $u = sfStaff($role);
            $this->actingAs($u)->getJson('/api/v1/admin/dashboard')->assertForbidden()->assertJsonPath('code', 'two_factor_required');
            $this->actingAs($u)->getJson('/api/v1/admin/events')->assertForbidden()->assertJsonPath('code', 'two_factor_required');
            $this->actingAs($u)->getJson('/api/v1/admin/userInfo')->assertOk()->assertJsonPath('data.security.twoFactorSetupRequired', true);
            $this->actingAs($u)->getJson('/api/v1/admin/account')->assertOk()->assertJsonPath('data.twoFactor.required', true);
            $this->actingAs($u)->postJson('/api/v1/admin/account/two-factor/setup', ['password' => 'password'])->assertOk();
        }
    });

    it('lets in the people who have two-factor, and does not touch organizers', function () {
        sfSwitch(true);

        $this->actingAs(sfStaff(enrolled: true))->getJson('/api/v1/admin/dashboard')->assertOk();
        $this->actingAs(sfStaff(AdminPermissions::SUPPORT, true))->getJson('/api/v1/admin/dashboard')->assertOk();
        $this->actingAs(User::factory()->create())->getJson('/api/v1/admin/dashboard')->assertForbidden()->assertJsonMissingPath('code');
        $this->actingAs(User::factory()->create())->getJson('/api/v1/app/events')->assertOk();
    });

    it('applies within seconds after the toggle and stops applying when it is turned off', function () {
        $super = sfStaff(enrolled: true);
        $support = sfStaff(AdminPermissions::SUPPORT);
        $this->actingAs($support)->getJson('/api/v1/admin/dashboard')->assertOk();

        sfAs($super)->putJson(SF_URL, ['enabled' => true, 'reason' => SF_REASON])->assertOk();
        $this->actingAs($support)->getJson('/api/v1/admin/dashboard')->assertForbidden()->assertJsonPath('code', 'two_factor_required');

        sfAs($super)->putJson(SF_URL, ['enabled' => false, 'reason' => 'Ya no hace falta'])->assertOk();
        $this->actingAs($support)->getJson('/api/v1/admin/dashboard')->assertOk();
    });

    it('keeps the environment variable working for superadmins, with the switch off, and ORed with it', function () {
        config(['admin.require_two_factor_superadmin' => true]);
        $super = sfStaff();
        $support = sfStaff(AdminPermissions::SUPPORT);

        $this->actingAs($super)->getJson('/api/v1/admin/dashboard')->assertForbidden()->assertJsonPath('code', 'two_factor_required');
        $this->actingAs($support)->getJson('/api/v1/admin/dashboard')->assertOk();

        // The panel cannot turn the environment rule off: the OR keeps the superadmin blocked after "off".
        sfSwitch(false);
        $this->actingAs($super)->getJson('/api/v1/admin/dashboard')->assertForbidden()->assertJsonPath('code', 'two_factor_required');
        sfSwitch(true);
        $this->actingAs($support)->getJson('/api/v1/admin/dashboard')->assertForbidden()->assertJsonPath('code', 'two_factor_required');
    });

    it('fails open when the flag cannot be read: nobody is locked out by a broken switch', function () {
        Schema::drop('system_flags');
        Cache::forget(TwoFactorEnforcement::CACHE_KEY);

        expect(app(TwoFactorEnforcement::class)->enabled())->toBeFalse();
    });
});

describe('impersonation', function () {
    it('cannot reach the switch with an impersonation token', function () {
        $support = sfStaff(AdminPermissions::SUPPORT);
        $owner = User::factory()->create();
        app('auth')->forgetGuards();
        $token = $this->postJson("/api/v1/admin/users/{$owner->id}/impersonate", ['reason' => 'Revisar un problema'], ['Authorization' => 'Bearer ' . $support->createToken('t')->plainTextToken])
            ->assertCreated()->json('token');

        foreach (['getJson' => [SF_URL], 'putJson' => [SF_URL, ['enabled' => false, 'reason' => SF_REASON]]] as $method => $args) {
            app('auth')->forgetGuards();
            $this->withHeader('Authorization', 'Bearer ' . $token)->{$method}(...$args)->assertForbidden();
        }
        expect(DB::table('system_flags')->where('key', TwoFactorEnforcement::KEY)->exists())->toBeFalse();
    });
});

describe('emergency command', function () {
    it('turns the switch off from the console and audits it', function () {
        sfSwitch(true);

        $this->artisan('admin:require-2fa', ['action' => 'off'])->assertSuccessful();

        expect(app(TwoFactorEnforcement::class)->enabled())->toBeFalse()
            ->and(AdminAuditLog::where('action', 'system.require_two_factor_off')->count())->toBe(1);
        $this->artisan('admin:require-2fa', ['action' => 'nope'])->assertFailed();
    });
});
