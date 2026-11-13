<?php

use App\Events\ResetPasswordEvent;
use App\Models\AdminAuditLog;
use App\Models\EventPlan;
use App\Models\EventPurchase;
use App\Models\Events;
use App\Models\EventType;
use App\Models\EventUserRole;
use App\Models\Guest;
use App\Models\User;
use App\Models\UserLoginSession;
use App\Support\AdminPermissions;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\InvitationTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

const ADMIN_USERS = '/api/v1/admin/users';

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    User::role(AdminPermissions::STAFF_ROLES)->get()->each(fn (User $u) => $u->syncRoles([]));
});

function usersStaff(string $role): User
{
    $u = User::factory()->create();
    $u->assignRole($role);

    return $u;
}

function usersEvent(User $user, string $role = 'owner', string $status = 'published'): Events
{
    $event = Events::factory()->create([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', 'basic')->value('id'),
        'status' => $status,
        'published_at' => $status === 'published' ? now() : null,
    ]);
    EventUserRole::create(['event_id' => $event->id, 'user_id' => $user->id, 'role_id' => Role::where('name', $role)->value('id')]);

    return $event;
}

function usersPurchase(User $user, Events $event, int $cents = 9900): EventPurchase
{
    return EventPurchase::create([
        'event_id' => $event->id, 'user_id' => $user->id, 'event_plan_id' => $event->event_plan_id, 'gateway' => 'manual',
        'list_price_cents' => $cents, 'amount_cents' => $cents, 'currency' => 'USD', 'status' => 'paid', 'paid_at' => now(),
    ]);
}

describe('permissions', function () {
    it('answers 401 to anonymous and 403 to normal users', function () {
        $target = User::factory()->create();
        $normal = User::factory()->create();

        $routes = [['get', ''], ['get', "/{$target->id}"], ['post', "/{$target->id}/password-reset"], ['post', "/{$target->id}/suspend"],
            ['post', "/{$target->id}/unsuspend"], ['get', "/{$target->id}/export"], ['delete', "/{$target->id}"]];

        foreach ($routes as [$m, $path]) {
            $this->json($m, ADMIN_USERS . $path)->assertUnauthorized();
        }
        foreach ($routes as [$m, $path]) {
            $this->actingAs($normal)->json($m, ADMIN_USERS . $path)->assertForbidden();
        }
    });

    it('lets support look and reset passwords but nothing else', function () {
        $support = usersStaff('support');
        $target = User::factory()->create();
        Event::fake([ResetPasswordEvent::class]);

        $this->actingAs($support)->getJson(ADMIN_USERS)->assertOk();
        $this->actingAs($support)->getJson(ADMIN_USERS . "/{$target->id}")->assertOk();
        $this->actingAs($support)->postJson(ADMIN_USERS . "/{$target->id}/password-reset")->assertOk();
        $this->actingAs($support)->postJson(ADMIN_USERS . "/{$target->id}/suspend", ['reason' => 'Abuse reports'])->assertForbidden();
        $this->actingAs($support)->postJson(ADMIN_USERS . "/{$target->id}/unsuspend", ['reason' => 'Abuse reports'])->assertForbidden();
        $this->actingAs($support)->getJson(ADMIN_USERS . "/{$target->id}/export")->assertForbidden();
        $this->actingAs($support)->deleteJson(ADMIN_USERS . "/{$target->id}", ['reason' => 'Because', 'confirmEmail' => $target->email])->assertForbidden();
    });
});

describe('listing and profile', function () {
    it('searches, filters and hides internal accounts by default', function () {
        $admin = usersStaff('superadmin');
        $ana = User::factory()->create(['name' => 'Ana Customer', 'email' => 'ana@example.com', 'phone' => '+13055550101']);
        $demo = User::factory()->create(['name' => 'Demo Person', 'email' => 'demo@cwm.test']);
        $sus = User::factory()->create(['name' => 'Susp', 'email' => 'susp@example.com']);
        $sus->forceFill(['suspended_at' => now()])->save();
        $support = usersStaff('support');

        $ids = fn (string $q = '') => collect($this->actingAs($admin)->getJson(ADMIN_USERS . $q)->assertOk()->json('data'))->pluck('id')->all();

        expect($ids())->toContain($ana->id, $sus->id)->not->toContain($demo->id, $support->id, $admin->id)
            ->and($ids('?internal=1'))->toContain($demo->id, $support->id, $admin->id)
            ->and($ids('?kind=staff'))->toContain($support->id, $admin->id)->not->toContain($ana->id)
            ->and($ids('?kind=customer'))->toContain($ana->id)->not->toContain($support->id)
            ->and($ids('?status=suspended'))->toBe([$sus->id])
            ->and($ids('?status=active'))->not->toContain($sus->id)
            ->and($ids('?search=3055550101'))->toBe([$ana->id])
            ->and($ids('?search=ana%40exam'))->toBe([$ana->id]);
    });

    it('shows the row fields and paginates', function () {
        $admin = usersStaff('superadmin');
        $ana = User::factory()->create(['name' => 'Ana']);
        usersEvent($ana);
        usersEvent($ana, 'editor');
        UserLoginSession::create(['user_id' => $ana->id, 'login_time' => '2026-11-01 10:00:00']);
        UserLoginSession::create(['user_id' => $ana->id, 'login_time' => '2026-11-05 10:00:00']);

        $res = $this->actingAs($admin)->getJson(ADMIN_USERS . '?search=Ana&perPage=5')->assertOk();
        $row = $res->json('data.0');

        expect($row)->toHaveKeys(['id', 'name', 'email', 'phone', 'staffRoles', 'eventsCount', 'suspended', 'lastAccessAt', 'createdAt'])
            ->and($row['eventsCount'])->toBe(2)
            ->and($row['lastAccessAt'])->toStartWith('2026-11-05')
            ->and($res->json('meta'))->toMatchArray(['page' => 1, 'perPage' => 5]);
    });

    it('does not run more queries for more users', function () {
        $admin = usersStaff('superadmin');
        User::factory()->count(3)->create()->each(fn ($u) => usersEvent($u));
        $count = function () use ($admin) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($admin)->getJson(ADMIN_USERS . '?perPage=100')->assertOk();

            return count(DB::getQueryLog());
        };
        $count();
        $few = $count();
        User::factory()->count(12)->create()->each(fn ($u) => usersEvent($u));

        expect($count())->toBe($few);
    });

    it('builds the profile with events, purchases, sessions and suspension', function () {
        $admin = usersStaff('superadmin');
        $ana = User::factory()->create();
        $event = usersEvent($ana);
        usersEvent($ana, 'viewer', 'draft');
        usersPurchase($ana, $event, 9900);
        UserLoginSession::create(['user_id' => $ana->id, 'login_time' => now(), 'ip_address' => '1.2.3.4', 'browser' => 'Firefox']);
        $this->actingAs($admin)->postJson(ADMIN_USERS . "/{$ana->id}/suspend", ['reason' => 'Spam invitations'])->assertOk();

        $d = $this->actingAs($admin)->getJson(ADMIN_USERS . "/{$ana->id}")->assertOk()->json('data');

        expect($d['events'])->toHaveCount(2)
            ->and(collect($d['events'])->pluck('role')->all())->toEqualCanonicalizing(['owner', 'viewer'])
            ->and($d['events'][0])->toHaveKeys(['id', 'name', 'role', 'status', 'plan'])
            ->and($d['purchases'])->toMatchArray(['paidCount' => 1, 'paidTotalCents' => 9900, 'currency' => 'USD'])
            ->and($d['purchases'])->not->toHaveKey('gateway')
            ->and($d['recentSessions'][0]['ip'])->toBe('1.2.3.4')
            ->and($d['suspension']['reason'])->toBe('Spam invitations')
            ->and($d['suspension']['by']['id'])->toBe($admin->id);
    });
});

describe('password reset', function () {
    it('dispatches the reset email flow and audits', function () {
        $support = usersStaff('support');
        $target = User::factory()->create();
        Event::fake([ResetPasswordEvent::class]);

        $this->actingAs($support)->postJson(ADMIN_USERS . "/{$target->id}/password-reset")->assertOk();

        Event::assertDispatched(ResetPasswordEvent::class, fn ($e) => $e->user->is($target));
        expect(AdminAuditLog::where('action', 'user.password_reset')->where('user_id', $target->id)->where('actor_id', $support->id)->count())->toBe(1);
    });
});

describe('suspension', function () {
    it('requires a reason and cannot hit staff or self', function () {
        $admin = usersStaff('superadmin');
        $other = usersStaff('support');
        $target = User::factory()->create();

        $this->actingAs($admin)->postJson(ADMIN_USERS . "/{$target->id}/suspend")->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->actingAs($admin)->postJson(ADMIN_USERS . "/{$target->id}/suspend", ['reason' => 'abc'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->actingAs($admin)->postJson(ADMIN_USERS . "/{$other->id}/suspend", ['reason' => 'Not allowed'])->assertStatus(422);
        $this->actingAs($admin)->postJson(ADMIN_USERS . "/{$admin->id}/suspend", ['reason' => 'Not allowed'])->assertStatus(422);
        expect(User::whereNotNull('suspended_at')->count())->toBe(0)
            ->and(AdminAuditLog::count())->toBe(0);
    });

    it('revokes tokens, blocks old tokens and login, and unsuspending restores access', function () {
        config(['services.support.email' => 'help@example.com']);
        $admin = usersStaff('superadmin');
        $target = User::factory()->create(['password' => bcrypt('secret-pass-1')]);
        $token = $target->createToken('spa')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/app/user')->assertOk();
        $this->actingAs($admin)->postJson(ADMIN_USERS . "/{$target->id}/suspend", ['reason' => 'Fraud reports'])->assertOk();

        expect($target->tokens()->count())->toBe(0);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/app/user')->assertUnauthorized();

        // A token that somehow survives is still refused by the middleware.
        $fresh = $target->refresh()->createToken('late')->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withToken($fresh)->getJson('/api/v1/app/user')->assertForbidden()
            ->assertJson(['code' => 'account_suspended', 'supportEmail' => 'help@example.com']);
        $this->app['auth']->forgetGuards();
        $this->withToken($fresh)->getJson("/api/v1/app/user/hydrate/{$target->id}")->assertForbidden()->assertJson(['code' => 'account_suspended']);

        $this->postJson('/api/v1/app/login', ['device' => 'test', 'email' => $target->email, 'password' => 'secret-pass-1'])
            ->assertForbidden()->assertJson(['code' => 'account_suspended', 'supportEmail' => 'help@example.com']);
        $this->postJson('/api/v1/app/login', ['device' => 'test', 'email' => $target->email, 'password' => 'wrong'])->assertUnauthorized();

        $this->actingAs($admin)->postJson(ADMIN_USERS . "/{$target->id}/unsuspend", ['reason' => 'Resolved with user'])->assertOk();
        expect($target->refresh()->suspended_at)->toBeNull()->and($target->suspension_reason)->toBeNull();
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web'); // actingAs() switched the default guard to sanctum
        $this->postJson('/api/v1/app/login', ['device' => 'test', 'email' => $target->email, 'password' => 'secret-pass-1'])->assertOk();
        expect(AdminAuditLog::orderBy('id')->pluck('action')->all())->toBe(['user.suspend', 'user.unsuspend']);
        expect(AdminAuditLog::where('action', 'user.suspend')->first()->reason)->toBe('Fraud reports');
    });

    it('keeps the published invitation of a suspended user visible to guests', function () {
        $this->seed(InvitationTemplateSeeder::class);
        $admin = usersStaff('superadmin');
        $owner = User::factory()->create();
        $event = usersEvent($owner);
        $event->update(['custom_url_slug' => 'xv-de-ana']);
        $guest = Guest::factory()->create(['event_id' => $event->id, 'code' => 'AB1234', 'parent_id' => null, 'rsvp_status' => 'pending']);
        $this->getJson('/api/v1/app/invitation/xv-de-ana/AB1234')->assertOk();

        $this->actingAs($admin)->postJson(ADMIN_USERS . "/{$owner->id}/suspend", ['reason' => 'Spam invitations'])->assertOk();

        $this->getJson('/api/v1/app/invitation/xv-de-ana/AB1234')->assertOk();
    });
});

describe('export', function () {
    it('downloads personal data, events, guests and purchases, and audits', function () {
        $admin = usersStaff('superadmin');
        $ana = User::factory()->create(['name' => 'Ana', 'phone' => '+13055550101']);
        $event = usersEvent($ana);
        Guest::factory()->create(['event_id' => $event->id, 'name' => 'Rosa', 'parent_id' => null]);
        usersPurchase($ana, $event);

        $res = $this->actingAs($admin)->get(ADMIN_USERS . "/{$ana->id}/export")->assertOk();
        $data = $res->json();

        expect($res->headers->get('Content-Disposition'))->toContain('attachment')
            ->and($data['user']['email'])->toBe($ana->email)
            ->and($data['events'])->toHaveCount(1)
            ->and($data['events'][0]['guests'][0]['name'])->toBe('Rosa')
            ->and($data['purchases'][0]['amountCents'])->toBe(9900)
            ->and(json_encode($data))->not->toContain('password');
        expect(AdminAuditLog::where('action', 'user.export')->where('user_id', $ana->id)->count())->toBe(1);
    });
});

describe('delete (anonymise)', function () {
    it('validates reason, confirmEmail, staff and self', function () {
        $admin = usersStaff('superadmin');
        $staff = usersStaff('support');
        $target = User::factory()->create();

        $this->actingAs($admin)->deleteJson(ADMIN_USERS . "/{$target->id}")->assertStatus(422)->assertJsonValidationErrors(['reason', 'confirmEmail']);
        $this->actingAs($admin)->deleteJson(ADMIN_USERS . "/{$target->id}", ['reason' => 'Asked by user', 'confirmEmail' => 'nope@example.com'])
            ->assertStatus(422)->assertJsonValidationErrors('confirmEmail');
        $this->actingAs($admin)->deleteJson(ADMIN_USERS . "/{$staff->id}", ['reason' => 'Asked by user', 'confirmEmail' => $staff->email])->assertStatus(422);
        $this->actingAs($admin)->deleteJson(ADMIN_USERS . "/{$admin->id}", ['reason' => 'Asked by user', 'confirmEmail' => $admin->email])->assertStatus(422);

        expect($target->refresh()->anonymized_at)->toBeNull()->and(AdminAuditLog::count())->toBe(0);
    });

    it('anonymises, keeps purchases, archives sole-owned events and revokes tokens', function () {
        $admin = usersStaff('superadmin');
        $ana = User::factory()->create(['name' => 'Ana', 'phone' => '+13055550101', 'avatar' => 'avatars/ana.png']);
        $email = $ana->email;
        $solo = usersEvent($ana);
        $shared = usersEvent($ana);
        $coOwner = User::factory()->create();
        EventUserRole::create(['event_id' => $shared->id, 'user_id' => $coOwner->id, 'role_id' => Role::where('name', 'owner')->value('id')]);
        $editing = usersEvent($ana, 'editor');
        $purchase = usersPurchase($ana, $solo);
        $ana->createToken('spa');

        $res = $this->actingAs($admin)->deleteJson(ADMIN_USERS . "/{$ana->id}", ['reason' => 'Asked to erase data', 'confirmEmail' => strtoupper($email)])->assertOk();

        $ana->refresh();
        expect($ana->name)->not->toBe('Ana')
            ->and($ana->email)->toBe("deleted-{$ana->id}@deleted.invalid")
            ->and($ana->phone)->toBeNull()->and($ana->avatar)->toBeNull()
            ->and($ana->anonymized_at)->not->toBeNull()
            ->and($ana->tokens()->count())->toBe(0)
            ->and($res->json('archivedEvents'))->toBe([$solo->id])
            ->and($solo->refresh()->status)->toBe('archived')
            ->and($shared->refresh()->status)->toBe('published')
            ->and($editing->refresh()->status)->toBe('published')
            ->and(EventPurchase::find($purchase->id)->user_id)->toBe($ana->id)
            ->and(EventPurchase::find($purchase->id)->amount_cents)->toBe(9900);

        $log = AdminAuditLog::where('action', 'user.delete')->first();
        expect($log->user_id)->toBe($ana->id)->and($log->reason)->toBe('Asked to erase data')->and($log->before['email'])->toBe($email);

        // Cannot be deleted twice, and the old address no longer logs in.
        $this->actingAs($admin)->deleteJson(ADMIN_USERS . "/{$ana->id}", ['reason' => 'Asked to erase data', 'confirmEmail' => $ana->email])->assertStatus(422);
        $this->postJson('/api/v1/app/login', ['device' => 'test', 'email' => $email, 'password' => 'password'])->assertUnauthorized();
    });
});
