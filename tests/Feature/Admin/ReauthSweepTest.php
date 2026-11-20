<?php

use App\Models\AdminAuditLog;
use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\EventUserRole;
use App\Models\Guest;
use App\Models\User;
use App\Support\AdminPermissions;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    User::role(AdminPermissions::STAFF_ROLES)->get()->each(fn (User $u) => $u->syncRoles([]));
});

/** The sensitive admin routes that must sit behind `admin.reauth` (audit round 6, A-1), as "METHOD uri". */
function reauthSweepRoutes(): array
{
    return [
        'POST api/v1/admin/payments/events/{event}/manual',
        'POST api/v1/admin/payments/{purchase}/resolve-review',
        'PATCH api/v1/admin/events/{event}/plan',
        'PATCH api/v1/admin/catalog/plans/{slug}',
        'PUT api/v1/admin/catalog/modules/{module}/plans',
        'PATCH api/v1/admin/catalog/modules/{module}',
        'PUT api/v1/admin/system/maintenance',
        'POST api/v1/admin/health/failed-jobs/retry',
        'POST api/v1/admin/health/failed-jobs/{uuid}/retry',
        'DELETE api/v1/admin/health/failed-jobs/{uuid}',
        'GET api/v1/admin/users/{user}/export',
        'POST api/v1/admin/users/{user}/suspend',
        'POST api/v1/admin/users/{user}/unsuspend',
        'POST api/v1/admin/users',
        'POST api/v1/admin/users/{user}/impersonate',
        'POST api/v1/admin/events/{event}/transfer',
        'POST api/v1/admin/events/{event}/{action}',
        'PUT api/v1/admin/events/{event}/slug',
        'POST api/v1/admin/invoices/{invoice}/resend',
        'DELETE api/v1/admin/moderation/{type}/{id}',
        'POST api/v1/admin/moderation/bulk',
        'PUT api/v1/admin/events/{event}/modules/{module}',
        'DELETE api/v1/admin/events/{event}/modules/{module}',
    ];
}

it('puts admin.reauth after admin.can on every sensitive admin route', function () {
    $found = [];
    foreach (Route::getRoutes() as $route) {
        foreach ($route->methods() as $method) {
            $key = "$method {$route->uri()}";
            if (! in_array($key, reauthSweepRoutes(), true)) {
                continue;
            }
            $mw = array_values(array_filter($route->gatherMiddleware(), 'is_string'));
            $can = collect($mw)->search(fn ($m) => str_starts_with($m, 'admin.can:'));
            $re = array_search('admin.reauth', $mw, true);
            expect($can)->not->toBeFalse("$key has no admin.can")
                ->and($re)->not->toBeFalse("$key has no admin.reauth")
                ->and($re)->toBeGreaterThan($can, "$key: admin.reauth must come after admin.can");
            $found[] = $key;
        }
    }
    expect($found)->toHaveCount(count(reauthSweepRoutes()));
});

it('leaves password reset and the light moderation actions without reauth', function () {
    $free = ['POST api/v1/admin/users/{user}/password-reset',
        'POST api/v1/admin/moderation/{type}/{id}/hide', 'POST api/v1/admin/moderation/{type}/{id}/restore'];
    $seen = 0;
    foreach (Route::getRoutes() as $route) {
        foreach ($route->methods() as $method) {
            if (in_array("$method {$route->uri()}", $free, true)) {
                expect($route->gatherMiddleware())->not->toContain('admin.reauth');
                $seen++;
            }
        }
    }
    expect($seen)->toBe(count($free));
});

function reauthSweepStaff(string $role = 'superadmin'): User
{
    $u = User::factory()->create();
    $u->assignRole($role);

    return $u;
}

function reauthSweepEvent(User $owner): Events
{
    $event = Events::factory()->create([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', 'basic')->value('id'),
    ]);
    EventUserRole::create(['event_id' => $event->id, 'user_id' => $owner->id, 'role_id' => Role::where('name', 'owner')->value('id')]);

    return $event;
}

it('answers 403 reauth_required without a fresh reauth and goes on with one', function () {
    $admin = reauthSweepStaff();
    $customer = User::factory()->create();
    $event = reauthSweepEvent($customer);
    $stale = ['Authorization' => 'Bearer ' . $this->adminBearer($admin)];
    $fresh = ['Authorization' => 'Bearer ' . $this->adminBearer($admin, true)];

    $calls = [
        ['post', "/api/v1/admin/payments/events/{$event->id}/manual"],
        ['patch', "/api/v1/admin/events/{$event->id}/plan"],
        ['put', '/api/v1/admin/system/maintenance'],
        ['post', '/api/v1/admin/health/failed-jobs/retry'],
        ['get', "/api/v1/admin/users/{$customer->id}/export"],
        ['post', "/api/v1/admin/users/{$customer->id}/suspend"],
        ['post', "/api/v1/admin/users/{$customer->id}/impersonate"],
        ['post', '/api/v1/admin/users'],
        ['post', "/api/v1/admin/events/{$event->id}/transfer"],
        ['post', "/api/v1/admin/events/{$event->id}/archive"],
        ['put', "/api/v1/admin/events/{$event->id}/slug"],
        ['post', '/api/v1/admin/moderation/bulk'],
        ['put', "/api/v1/admin/events/{$event->id}/modules/messaging"],
        ['delete', "/api/v1/admin/events/{$event->id}/modules/messaging"],
    ];
    foreach ($calls as [$m, $url]) {
        app('auth')->forgetGuards();
        $this->withHeaders($stale)->json($m, $url)->assertForbidden()->assertJsonPath('code', 'reauth_required');
        app('auth')->forgetGuards();
        $res = $this->withHeaders($fresh)->json($m, $url);
        expect($res->json('code'))->not->toBe('reauth_required', "$m $url");
        expect($res->status())->not->toBe(403, "$m $url");
    }
});

describe('user export (A-2)', function () {
    function reauthSweepOwnerWithGuest(): array
    {
        $owner = User::factory()->create();
        $event = reauthSweepEvent($owner);
        Guest::factory()->count(2)->create(['event_id' => $event->id]);

        return [$owner, $event];
    }

    it('needs a reason', function () {
        [$owner] = reauthSweepOwnerWithGuest();
        $this->actingAsReauthed(reauthSweepStaff())->getJson("/api/v1/admin/users/{$owner->id}/export")
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
    });

    it('drops the guest access codes and audits the reason and the guest count', function () {
        [$owner] = reauthSweepOwnerWithGuest();
        $admin = reauthSweepStaff();
        $res = $this->actingAsReauthed($admin)->getJson("/api/v1/admin/users/{$owner->id}/export?reason=" . urlencode('Data request from the owner'))->assertOk();

        $guests = $res->json('events.0.guests');
        expect($guests)->toHaveCount(2);
        foreach ($guests as $g) {
            expect($g)->not->toHaveKey('code')->and($g)->toHaveKeys(['name', 'email', 'phone', 'rsvp_status']);
        }
        $row = AdminAuditLog::where('action', 'user.export')->firstOrFail();
        expect($row->reason)->toBe('Data request from the owner')->and($row->after)->toBe(['events' => 1, 'guests' => 2]);
    });
});

describe('password reset (B-1, B-2)', function () {
    it('needs a reason and audits it', function () {
        $target = User::factory()->create();
        $support = reauthSweepStaff('support');
        $this->actingAs($support)->postJson("/api/v1/admin/users/{$target->id}/password-reset")->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAs($support)->postJson("/api/v1/admin/users/{$target->id}/password-reset", ['reason' => 'User locked out of the account'])->assertOk();
        expect(AdminAuditLog::where('action', 'user.password_reset')->firstOrFail()->reason)->toBe('User locked out of the account');
    });

    it('refuses staff accounts and oneself', function () {
        $support = reauthSweepStaff('support');
        $other = reauthSweepStaff('superadmin');
        foreach ([$other] as $target) {
            $this->actingAs($support)->postJson("/api/v1/admin/users/{$target->id}/password-reset", ['reason' => 'Trying it out here'])
                ->assertUnprocessable()->assertJsonValidationErrors('user');
        }
        expect(AdminAuditLog::where('action', 'user.password_reset')->count())->toBe(0);
    });
});

it('refuses a password reset on oneself', function () {
    $support = reauthSweepStaff('support');
    $this->actingAs($support)->postJson("/api/v1/admin/users/{$support->id}/password-reset", ['reason' => 'Trying it out here'])->assertUnprocessable();
});
