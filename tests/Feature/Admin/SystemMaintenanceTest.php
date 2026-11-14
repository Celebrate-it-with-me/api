<?php

use App\Http\Services\Admin\System\MaintenanceMode;
use App\Models\User;
use App\Support\AdminPermissions;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const MAINT_URL = '/api/v1/admin/system/maintenance';

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    User::role(AdminPermissions::STAFF_ROLES)->get()->each(fn (User $u) => $u->syncRoles([]));
    Cache::forget(MaintenanceMode::CACHE_KEY);
});

function maintStaff(string $role): User
{
    $u = User::factory()->create();
    $u->assignRole($role);

    return $u;
}

function maintOn(?User $by = null, array $extra = []): void
{
    app(MaintenanceMode::class)->enable($extra['message'] ?? 'Volvemos pronto', $extra['end'] ?? null, $by);
}

it('guards the maintenance endpoints by permission', function () {
    $this->getJson(MAINT_URL)->assertUnauthorized();
    $this->actingAs(User::factory()->create())->getJson(MAINT_URL)->assertForbidden();
    $this->actingAs(maintStaff(AdminPermissions::SUPPORT))->getJson(MAINT_URL)->assertForbidden();
    $this->actingAs(maintStaff(AdminPermissions::SUPPORT))->putJson(MAINT_URL, ['active' => true, 'reason' => 'Migración grande'])->assertForbidden();
    $this->actingAs(maintStaff(AdminPermissions::SUPERADMIN))->getJson(MAINT_URL)->assertOk()->assertJsonPath('active', false);
});

it('requires a reason and validates the estimated end', function () {
    $admin = maintStaff(AdminPermissions::SUPERADMIN);
    $this->actingAs($admin)->putJson(MAINT_URL, ['active' => true])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->actingAs($admin)->putJson(MAINT_URL, ['active' => true, 'reason' => 'abc'])->assertUnprocessable();
    $this->actingAs($admin)->putJson(MAINT_URL, ['active' => true, 'reason' => 'Migración grande', 'estimatedEnd' => now()->subHour()->toIso8601String()])->assertUnprocessable()->assertJsonValidationErrors('estimatedEnd');
    $this->actingAs($admin)->putJson(MAINT_URL, ['active' => true, 'reason' => 'Migración grande', 'estimatedEnd' => now()->addDays(3)->toIso8601String()])->assertUnprocessable();
    $this->actingAs($admin)->putJson(MAINT_URL, ['reason' => 'Migración grande'])->assertUnprocessable()->assertJsonValidationErrors('active');
    expect(app(MaintenanceMode::class)->active())->toBeFalse();
});

it('turns maintenance on and off, recording who and why in the audit log', function () {
    $admin = maintStaff(AdminPermissions::SUPERADMIN);
    $end = now()->addHour();

    $this->actingAs($admin)->putJson(MAINT_URL, ['active' => true, 'message' => 'Mejorando cosas', 'estimatedEnd' => $end->toIso8601String(), 'reason' => 'Migración grande'])
        ->assertOk()->assertJsonPath('active', true)->assertJsonPath('message', 'Mejorando cosas')->assertJsonPath('activatedBy.id', $admin->id);
    $this->actingAs($admin)->getJson(MAINT_URL)->assertJsonPath('active', true);
    $on = DB::table('admin_audit_log')->where('action', 'system.maintenance_on')->first();
    expect($on->actor_id)->toBe($admin->id)->and($on->reason)->toBe('Migración grande');

    $this->actingAs($admin)->putJson(MAINT_URL, ['active' => false, 'reason' => 'Terminamos'])->assertOk()->assertJsonPath('active', false);
    expect(DB::table('admin_audit_log')->where('action', 'system.maintenance_off')->count())->toBe(1);
    $this->getJson('/api/v1/public/system-status')->assertOk()->assertJsonPath('maintenance', false);
});

it('answers 503 JSON to the rest of the API while it is on', function () {
    maintOn(null, ['end' => now()->addMinutes(30)]);

    $res = $this->getJson('/api/v1/app/events');
    $res->assertStatus(503)->assertJsonPath('code', 'maintenance')->assertJsonPath('message', 'Volvemos pronto');
    expect($res->json('retryAfter'))->toBeGreaterThan(60)->toBeLessThanOrEqual(1800);
    expect($res->headers->get('Retry-After'))->not->toBeNull();
    $this->postJson('/api/v1/app/login', [])->assertStatus(503);
    $this->actingAs(User::factory()->create())->getJson('/api/v1/app/events')->assertStatus(503);
});

it('keeps the admin API, the staff login, the status endpoint and the webhook reachable', function () {
    maintOn();
    $admin = maintStaff(AdminPermissions::SUPERADMIN);

    $this->postJson('/api/v1/admin/login', ['email' => 'x@example.com', 'password' => 'nope', 'device' => 'd'])->assertStatus(422);
    $this->actingAs($admin)->getJson(MAINT_URL)->assertOk()->assertJsonPath('active', true);
    $this->actingAs($admin)->getJson('/api/v1/admin/dashboard')->assertOk();
    $this->getJson('/api/v1/public/system-status')->assertOk();
    expect($this->postJson('/api/v1/app/payments/webhook', [])->status())->not->toBe(503);

    // The superadmin can switch it off while it is on.
    $this->actingAs($admin)->putJson(MAINT_URL, ['active' => false, 'reason' => 'Terminamos'])->assertOk();
    expect(app(MaintenanceMode::class)->active())->toBeFalse();
    $this->getJson('/api/v1/public/system-status')->assertJsonPath('maintenance', false);
});

it('lets staff tokens through the public API while it is on', function () {
    maintOn();
    $token = maintStaff(AdminPermissions::SUPERADMIN)->createToken('t')->plainTextToken;
    $user = User::factory()->create()->createToken('t')->plainTextToken;

    $this->withToken($user)->getJson('/api/v1/app/events')->assertStatus(503);
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/app/events')->assertSuccessful();
});

it('exposes only state, message and estimated end publicly', function () {
    $admin = maintStaff(AdminPermissions::SUPERADMIN);
    maintOn($admin);

    $res = $this->getJson('/api/v1/public/system-status')->assertOk();
    expect(array_keys($res->json()))->toBe(['maintenance', 'message', 'estimatedEnd']);
    expect($res->getContent())->not->toContain($admin->email)->not->toContain($admin->name)->not->toContain('activat');
    expect($res->json('maintenance'))->toBeTrue();
});

it('expires by itself and fails open', function () {
    maintOn();
    $this->travel(MaintenanceMode::MAX_HOURS + 1)->hours();
    Cache::forget(MaintenanceMode::CACHE_KEY);

    $this->getJson('/api/v1/public/system-status')->assertJsonPath('maintenance', false);
    $this->getJson('/api/v1/app/events')->assertStatus(401);
});

it('has an emergency command that turns it off and audits it', function () {
    maintOn();
    expect(app(MaintenanceMode::class)->active())->toBeTrue();

    $this->artisan('system:maintenance', ['action' => 'status'])->expectsOutput('Maintenance is ON.')->assertExitCode(0);
    $this->artisan('system:maintenance', ['action' => 'off'])->assertExitCode(0);

    expect(app(MaintenanceMode::class)->active())->toBeFalse();
    expect(DB::table('admin_audit_log')->where('action', 'system.maintenance_off')->count())->toBe(1);
    $this->artisan('system:maintenance', ['action' => 'maybe'])->assertExitCode(2);
});
