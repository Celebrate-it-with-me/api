<?php

use App\Models\AdminAuditLog;
use App\Models\EventPlan;
use App\Models\EventPurchase;
use App\Models\Events;
use App\Models\EventType;
use App\Models\EventUserRole;
use App\Models\User;
use App\Support\AdminPermissions;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    // The seeded admin of the local environment would count as a superadmin: start with an empty team.
    User::role(AdminPermissions::STAFF_ROLES)->get()->each(fn (User $u) => $u->syncRoles([]));
});

function staffAs(string $role, ?string $email = null): User
{
    $user = User::factory()->create($email ? ['email' => $email] : []);
    $user->assignRole($role);

    return $user;
}

function eventFor(User $owner): Events
{
    $event = Events::factory()->draft()->create([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', 'basic')->value('id'),
    ]);
    EventUserRole::create(['event_id' => $event->id, 'user_id' => $owner->id, 'role_id' => Role::where('name', 'owner')->value('id')]);

    return $event;
}

describe('roles and permissions', function () {
    it('gives superadmin everything and support only what the brief allows', function () {
        $super = Role::findByName('superadmin')->permissions->pluck('name')->all();
        $support = Role::findByName('support')->permissions->pluck('name')->all();

        expect($super)->toContain(...AdminPermissions::all())
            ->and($support)->toEqualCanonicalizing(AdminPermissions::SUPPORT_PERMISSIONS)
            ->and($support)->toContain('admin.content.moderate', 'admin.modules.gift', 'admin.impersonate')
            ->and($support)->not->toContain('admin.refunds', 'admin.pricing.manage', 'admin.team.manage', 'admin.users.delete', 'admin.audit.view');
    });

    it('lets support look but not touch money, pricing or the team', function () {
        $support = staffAs('support');
        $event = eventFor(User::factory()->create());

        $this->actingAs($support)->getJson('/api/v1/admin/dashboard')->assertOk();
        $this->actingAs($support)->getJson('/api/v1/admin/users')->assertOk();
        $this->actingAs($support)->getJson('/api/v1/admin/payments')->assertForbidden();
        $this->actingAs($support)->putJson('/api/v1/admin/payments/price', ['priceCents' => 100, 'reason' => 'trying it out'])->assertForbidden();
        $this->actingAs($support)->postJson("/api/v1/admin/payments/events/{$event->id}/manual", ['amountCents' => 1, 'reason' => 'trying it out'])->assertForbidden();
        $this->actingAs($support)->postJson('/api/v1/admin/payments/1/refund', ['reason' => 'trying it out'])->assertForbidden();
        $this->actingAs($support)->getJson('/api/v1/admin/team')->assertForbidden();
        $this->actingAs($support)->getJson('/api/v1/admin/audit')->assertForbidden();
        $this->actingAs($support)->deleteJson('/api/v1/admin/users/' . User::factory()->create()->id, ['reason' => 'trying it out'])->assertForbidden();
    });

    it('tells the panel the roles and permissions of whoever is logged in', function () {
        $res = $this->actingAs(staffAs('support'))->getJson('/api/v1/admin/userInfo')->assertOk();

        expect($res->json('data.roles'))->toBe(['support'])
            ->and($res->json('data.permissions'))->toEqualCanonicalizing(AdminPermissions::SUPPORT_PERMISSIONS);
    });

    it('lets both staff roles in and keeps everyone else out', function () {
        $this->actingAs(staffAs('support'))->getJson('/api/v1/admin/dashboard')->assertOk();
        $this->actingAs(staffAs('superadmin'))->getJson('/api/v1/admin/dashboard')->assertOk();
        $this->actingAs(User::factory()->create())->getJson('/api/v1/admin/dashboard')->assertForbidden();
    });

    it('turns the old admin role into superadmin when the migration runs', function () {
        $migration = require database_path('migrations/2026_11_23_000001_admin_roles_and_audit_log.php');
        $migration->down();

        $old = User::factory()->create();
        $old->assignRole('admin');
        expect(Role::where('name', 'admin')->exists())->toBeTrue()->and(Role::where('name', 'superadmin')->exists())->toBeFalse();

        $migration->up();

        $old->refresh();
        expect($old->hasRole('superadmin'))->toBeTrue()->and(Role::where('name', 'admin')->exists())->toBeFalse()
            ->and($old->hasPermissionTo('admin.refunds'))->toBeTrue();
    });
});

describe('the audit log', function () {
    it('asks for a reason on every sensitive action', function () {
        $admin = staffAs('superadmin');
        $event = eventFor(User::factory()->create());

        $this->actingAs($admin)->putJson('/api/v1/admin/payments/price', ['priceCents' => 8900])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->actingAs($admin)->putJson('/api/v1/admin/payments/price', ['priceCents' => 8900, 'reason' => 'abc'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->actingAs($admin)->postJson("/api/v1/admin/payments/events/{$event->id}/manual", ['amountCents' => 9900])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->actingAs($admin)->postJson('/api/v1/admin/payments/coupons', ['code' => 'X', 'kind' => 'fixed_price', 'value' => 100])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->actingAs($admin)->deleteJson('/api/v1/admin/users/' . User::factory()->create()->id)->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->actingAs($admin)->postJson('/api/v1/admin/team', ['email' => 'a@example.com', 'role' => 'support', 'name' => 'A'])->assertStatus(422)->assertJsonValidationErrors('reason');

        expect(AdminAuditLog::where('action', '!=', 'auth.login')->count())->toBe(0);
    });

    it('writes who did what, to whom and why', function () {
        $admin = staffAs('superadmin');
        $owner = User::factory()->create();
        $event = eventFor($owner);

        $this->actingAs($admin)->withHeader('User-Agent', 'PestBrowser/1.0')
            ->postJson("/api/v1/admin/payments/events/{$event->id}/manual", ['userId' => $owner->id, 'amountCents' => 9900, 'reason' => 'Zelle received 11/13'])->assertCreated();

        $row = AdminAuditLog::where('action', 'payment.manual')->firstOrFail();
        expect($row->actor_id)->toBe($admin->id)->and($row->event_id)->toBe($event->id)->and($row->user_id)->toBe($owner->id)
            ->and($row->reason)->toBe('Zelle received 11/13')->and($row->subject_type)->toBe('purchase')
            ->and($row->before)->toBe(['plan' => 'basic'])->and($row->after['plan'])->toBe('premium')
            ->and($row->user_agent)->toBe('PestBrowser/1.0')->and($row->ip)->not->toBeNull();
    });

    it('records refunds, price changes, coupons and user changes', function () {
        $admin = staffAs('superadmin');
        $owner = User::factory()->create();
        $event = eventFor($owner);
        $id = $this->actingAs($admin)->postJson("/api/v1/admin/payments/events/{$event->id}/manual", ['userId' => $owner->id, 'amountCents' => 9900, 'reason' => 'Zelle received'])->json('data.id');

        $this->actingAs($admin)->postJson("/api/v1/admin/payments/{$id}/refund", ['reason' => 'Asked before publishing'])->assertOk();
        $this->actingAs($admin)->putJson('/api/v1/admin/payments/price', ['priceCents' => 8900, 'reason' => 'Launch price'])->assertOk();
        $couponId = $this->actingAs($admin)->postJson('/api/v1/admin/payments/coupons', ['code' => 'amigos', 'kind' => 'fixed_price', 'value' => 5900, 'reason' => 'Friends and family'])->assertCreated()->json('data.id');
        $this->actingAs($admin)->patchJson("/api/v1/admin/payments/coupons/{$couponId}", ['isActive' => false, 'reason' => 'Campaign over'])->assertOk();
        $victim = User::factory()->create();
        $this->actingAs($admin)->deleteJson("/api/v1/admin/users/{$victim->id}", ['reason' => 'Asked to delete their account', 'confirmEmail' => $victim->email])->assertOk();

        $actions = AdminAuditLog::orderBy('id')->pluck('action')->all();
        expect($actions)->toBe(['payment.manual', 'payment.refund', 'pricing.update', 'coupon.create', 'coupon.deactivate', 'user.delete']);
        expect(AdminAuditLog::where('action', 'pricing.update')->first()->before)->toBe(['priceCents' => 9900]);
        expect(AdminAuditLog::where('action', 'user.delete')->first()->user_id)->toBe($victim->id);
    });

    it('records a staff login', function () {
        $admin = User::factory()->create(['password' => bcrypt('s3cret-pass')]);
        $admin->assignRole('support');

        $this->postJson('/api/v1/admin/login', ['email' => $admin->email, 'password' => 's3cret-pass', 'device' => 'test'])->assertOk();

        expect(AdminAuditLog::where('action', 'auth.login')->where('actor_id', $admin->id)->count())->toBe(1);
    });

    it('cannot be changed or deleted by the application', function () {
        $row = AdminAuditLog::create(['actor_id' => staffAs('support')->id, 'action' => 'test.action']);

        expect(fn () => $row->update(['reason' => 'rewritten']))->toThrow(LogicException::class)
            ->and(fn () => $row->delete())->toThrow(LogicException::class)
            ->and(AdminAuditLog::count())->toBe(1);
    });

    it('lists the log with filters and pages', function () {
        $admin = staffAs('superadmin');
        $other = staffAs('support');
        AdminAuditLog::create(['actor_id' => $admin->id, 'action' => 'payment.manual', 'event_id' => 7]);
        AdminAuditLog::create(['actor_id' => $other->id, 'action' => 'payment.refund', 'event_id' => 8]);
        AdminAuditLog::create(['actor_id' => $other->id, 'action' => 'coupon.create']);
        AdminAuditLog::create(['actor_id' => $other->id, 'action' => 'coupon.create', 'created_at' => now()->subDays(40)]);

        $get = fn (string $q = '') => $this->actingAs($admin)->getJson('/api/v1/admin/audit' . $q)->assertOk()->json();

        expect($get('')['meta']['total'])->toBe(4)
            ->and($get('?actor=' . $other->id)['meta']['total'])->toBe(3)
            ->and($get('?action=payment')['meta']['total'])->toBe(2)           // the family
            ->and($get('?action=payment.refund')['meta']['total'])->toBe(1)    // one action
            ->and($get('?event=7')['meta']['total'])->toBe(1)
            ->and($get('?from=' . now()->subDays(5)->toDateString())['meta']['total'])->toBe(3)
            ->and($get('?to=' . now()->subDays(30)->toDateString())['meta']['total'])->toBe(1)
            ->and($get('?perPage=5')['data'][0]['actor'])->toHaveKeys(['id', 'name', 'email'])
            ->and($get('')['data'][0]['createdAt'])->not->toBeNull();
    });

    it('prunes rows older than the retention period, and only those', function () {
        $keep = AdminAuditLog::create(['action' => 'recent']);
        $old = AdminAuditLog::create(['action' => 'old']);
        AdminAuditLog::whereKey($old->id)->toBase()->update(['created_at' => now()->subYears(3)]);
        $edge = AdminAuditLog::create(['action' => 'one-year']);
        AdminAuditLog::whereKey($edge->id)->toBase()->update(['created_at' => now()->subYear()]);

        $this->artisan('admin:audit-prune')->assertSuccessful();

        expect(AdminAuditLog::pluck('action')->all())->toEqualCanonicalizing(['recent', 'one-year']);

        $this->artisan('admin:audit-prune --years=1')->assertSuccessful();
        // exactly one year old is on the edge: it may go; the recent one stays.
        expect(AdminAuditLog::where('action', 'recent')->exists())->toBeTrue();
    });

    it('reads the retention from the config', function () {
        expect(config('admin.audit_retention_years'))->toBe(2);
    });
});

describe('team', function () {
    it('lists the staff with their role', function () {
        $super = staffAs('superadmin');
        $support = staffAs('support');
        User::factory()->create();

        $data = $this->actingAs($super)->getJson('/api/v1/admin/team')->assertOk()->json('data');

        expect(collect($data)->pluck('role', 'id')->sortKeys()->all())->toBe(collect([$super->id => 'superadmin', $support->id => 'support'])->sortKeys()->all());
    });

    it('gives a role to an existing account', function () {
        $super = staffAs('superadmin');
        $user = User::factory()->create(['email' => 'lucia@example.com']);

        $res = $this->actingAs($super)->postJson('/api/v1/admin/team', ['email' => 'lucia@example.com', 'role' => 'support', 'reason' => 'New support person'])->assertCreated();

        expect($user->refresh()->hasRole('support'))->toBeTrue()->and($res->json('temporaryPassword'))->toBeNull()
            ->and(AdminAuditLog::where('action', 'team.add')->where('user_id', $user->id)->exists())->toBeTrue();
        $this->actingAs($super)->postJson('/api/v1/admin/team', ['email' => 'lucia@example.com', 'role' => 'superadmin', 'reason' => 'Again'])->assertStatus(422)->assertJsonValidationErrors('email');
    });

    it('creates the account with a temporary password shown once', function () {
        $super = staffAs('superadmin');

        $res = $this->actingAs($super)->postJson('/api/v1/admin/team', ['email' => 'new@example.com', 'name' => 'New Person', 'role' => 'support', 'reason' => 'Hired for support'])->assertCreated();
        $password = $res->json('temporaryPassword');

        expect($password)->toBeString()->and(strlen($password))->toBe(16);
        $this->postJson('/api/v1/admin/login', ['email' => 'new@example.com', 'password' => $password, 'device' => 'x'])->assertOk();
        $this->actingAs($super)->getJson('/api/v1/admin/team')->assertJsonMissing(['temporaryPassword' => $password]);
        // the password itself is never written to the log
        expect(json_encode(AdminAuditLog::all()->toArray()))->not->toContain($password);

        $this->actingAs($super)->postJson('/api/v1/admin/team', ['email' => 'noname@example.com', 'role' => 'support', 'reason' => 'Hired for support'])->assertStatus(422)->assertJsonValidationErrors('name');
    });

    it('changes a role and removes access, with a reason, and records it', function () {
        $super = staffAs('superadmin');
        $other = staffAs('superadmin');
        $support = staffAs('support');

        $this->actingAs($super)->patchJson("/api/v1/admin/team/{$support->id}", ['role' => 'superadmin', 'reason' => 'Promoted'])->assertOk()->assertJsonPath('data.role', 'superadmin');
        $this->actingAs($super)->patchJson("/api/v1/admin/team/{$support->id}", ['role' => 'support'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->actingAs($super)->deleteJson("/api/v1/admin/team/{$other->id}", ['reason' => 'Left the company'])->assertOk();

        expect($other->refresh()->hasAnyRole(AdminPermissions::STAFF_ROLES))->toBeFalse()->and($other->exists)->toBeTrue()
            ->and(AdminAuditLog::orderBy('id')->pluck('action')->all())->toBe(['team.role_changed', 'team.removed'])
            ->and(AdminAuditLog::where('action', 'team.role_changed')->first()->before)->toBe(['role' => 'support']);
        $this->actingAs($other)->getJson('/api/v1/admin/dashboard')->assertForbidden();
    });

    it('never lets the last superadmin go, or you change yourself', function () {
        $super = staffAs('superadmin');
        $second = staffAs('superadmin');

        // yourself
        $this->actingAs($super)->patchJson("/api/v1/admin/team/{$super->id}", ['role' => 'support', 'reason' => 'Testing myself'])->assertStatus(422);
        $this->actingAs($super)->deleteJson("/api/v1/admin/team/{$super->id}", ['reason' => 'Testing myself'])->assertStatus(422);

        // two superadmins: one can demote the other, but then the remaining one is the last
        $this->actingAs($super)->patchJson("/api/v1/admin/team/{$second->id}", ['role' => 'support', 'reason' => 'Step down'])->assertOk();
        $third = staffAs('support');
        $this->actingAs($super)->patchJson("/api/v1/admin/team/{$third->id}", ['role' => 'superadmin', 'reason' => 'Promote'])->assertOk();
        $this->actingAs($third)->deleteJson("/api/v1/admin/team/{$super->id}", ['reason' => 'Remove other'])->assertOk();

        // `third` is now the only superadmin and cannot remove themselves.
        expect(User::role('superadmin')->count())->toBe(1);
        $this->actingAs($third)->deleteJson("/api/v1/admin/team/{$third->id}", ['reason' => 'Last one'])->assertStatus(422);
        expect(User::role('superadmin')->count())->toBe(1);
    });

    it('does not let staff accounts be deleted from the users list', function () {
        $super = staffAs('superadmin');
        $support = staffAs('support');

        $this->actingAs($super)->deleteJson("/api/v1/admin/users/{$support->id}", ['reason' => 'Should not work'])->assertStatus(422);
        expect(User::find($support->id))->not->toBeNull();
    });
});
