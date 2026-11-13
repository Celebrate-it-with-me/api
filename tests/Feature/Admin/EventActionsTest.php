<?php

use App\Mail\ConfirmAccountEmail;
use App\Mail\InviteUnregisteredUserMail;
use App\Mail\OwnershipTransferredMail;
use App\Mail\WelcomeEmail;
use App\Models\AdminAuditLog;
use App\Models\EventActivity;
use App\Models\EventCollaborationInvite;
use App\Models\EventComment;
use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\EventUserRole;
use App\Models\Guest;
use App\Models\Padrino;
use App\Models\User;
use App\Support\AdminPermissions;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    User::role(AdminPermissions::STAFF_ROLES)->get()->each(fn (User $u) => $u->syncRoles([]));
});

function acStaff(string $role): User
{
    $u = User::factory()->create();
    $u->assignRole($role);

    return $u;
}

function acEvent(?User $owner = null, string $state = 'draft', string $plan = 'basic'): Events
{
    $event = Events::factory()->{$state}()->create([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', $plan)->value('id'),
    ]);
    $owner ??= User::factory()->create();
    EventUserRole::create(['event_id' => $event->id, 'user_id' => $owner->id, 'role_id' => Role::where('name', 'owner')->value('id')]);

    return $event;
}

describe('permissions', function () {
    it('allows actions to superadmin only', function () {
        $event = acEvent();
        $routes = [
            ['post', "/api/v1/admin/events/{$event->id}/publish"], ['post', "/api/v1/admin/events/{$event->id}/archive"],
            ['post', "/api/v1/admin/events/{$event->id}/resend-owner-email"], ['put', "/api/v1/admin/events/{$event->id}/slug"],
            ['post', "/api/v1/admin/events/{$event->id}/transfer"],
        ];
        foreach ($routes as [$m, $url]) {
            $this->app['auth']->forgetGuards();
            $this->{$m . 'Json'}($url)->assertUnauthorized();
            $this->actingAs(User::factory()->create())->{$m . 'Json'}($url)->assertForbidden();
            $this->actingAs(acStaff('support'))->{$m . 'Json'}($url)->assertForbidden();
            $this->actingAs(acStaff('superadmin'))->{$m . 'Json'}($url)->assertUnprocessable(); // passes the permission, fails on the reason
        }
    });

    it('lets support read content and the activity log but not the staff log', function () {
        $event = acEvent();
        foreach (['comments', 'images', 'padrinos', 'guests'] as $s) {
            $this->app['auth']->forgetGuards();
            $this->getJson("/api/v1/admin/events/{$event->id}/content?section={$s}")->assertUnauthorized();
            $this->actingAs(User::factory()->create())->getJson("/api/v1/admin/events/{$event->id}/content?section={$s}")->assertForbidden();
            $this->actingAs(acStaff('support'))->getJson("/api/v1/admin/events/{$event->id}/content?section={$s}")->assertOk();
        }
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/admin/events/{$event->id}/log")->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson("/api/v1/admin/events/{$event->id}/log")->assertForbidden();
        $this->actingAs(acStaff('support'))->getJson("/api/v1/admin/events/{$event->id}/log")->assertOk()->assertJsonPath('meta.canViewStaff', false);
        $this->actingAs(acStaff('support'))->getJson("/api/v1/admin/events/{$event->id}/log?source=staff")->assertForbidden();
        $this->actingAs(acStaff('superadmin'))->getJson("/api/v1/admin/events/{$event->id}/log?source=staff")->assertOk();
    });
});

describe('lifecycle', function () {
    it('publishes, unpublishes, archives and restores, auditing each one with its reason', function () {
        $admin = acStaff('superadmin');
        $event = acEvent();
        $base = "/api/v1/admin/events/{$event->id}";

        foreach ([['publish', 'published'], ['unpublish', 'draft'], ['archive', 'archived'], ['restore', 'draft']] as [$action, $status]) {
            $this->actingAs($admin)->postJson("$base/$action", ['reason' => 'Pedido de la dueña'])
                ->assertOk()->assertJsonPath('data.status', $status);
            $this->assertDatabaseHas('admin_audit_log', ['action' => "event.$action", 'event_id' => $event->id, 'actor_id' => $admin->id, 'reason' => 'Pedido de la dueña']);
        }
    });

    it('requires a reason and respects the transitions', function () {
        $admin = acStaff('superadmin');
        $event = acEvent();

        $this->actingAs($admin)->postJson("/api/v1/admin/events/{$event->id}/publish")->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAs($admin)->postJson("/api/v1/admin/events/{$event->id}/restore", ['reason' => 'motivo valido'])->assertUnprocessable()->assertJsonValidationErrors('status');
        expect(AdminAuditLog::count())->toBe(0);
    });

    it('flags the event activity as staff work, without naming the actor', function () {
        $admin = acStaff('superadmin');
        $event = acEvent();
        $this->actingAs($admin)->postJson("/api/v1/admin/events/{$event->id}/publish", ['reason' => 'Pedido de la dueña'])->assertOk();

        $row = EventActivity::where('event_id', $event->id)->where('type', 'event_published')->first();
        expect($row->actor_id)->toBeNull()->and($row->payload['by_staff'])->toBeTrue();

        $this->actingAs($admin)->getJson("/api/v1/admin/events/{$event->id}/log")
            ->assertJsonPath('data.0.type', 'event_published')->assertJsonPath('data.0.byStaff', true)->assertJsonPath('data.0.actor', null);
    });

    it('explains the 402 when the guest list is over the plan limit', function () {
        $event = acEvent();
        $event->eventPlan->update(['max_guests' => 2]);
        Guest::factory()->count(5)->create(['event_id' => $event->id]);

        $this->actingAs(acStaff('superadmin'))->postJson("/api/v1/admin/events/{$event->id}/publish", ['reason' => 'Pedido de la dueña'])
            ->assertStatus(402)->assertJsonPath('code', 'guest_limit_exceeded')->assertJsonPath('excess', 3);
        expect($event->refresh()->status)->toBe('draft')->and(AdminAuditLog::count())->toBe(0);
    });
});

describe('emails', function () {
    it('resends the confirmation to an unconfirmed owner and the welcome to a confirmed one', function () {
        Mail::fake();
        $admin = acStaff('superadmin');
        $owner = User::factory()->create(['email_verified_at' => null]);
        $event = acEvent($owner);
        $url = "/api/v1/admin/events/{$event->id}/resend-owner-email";

        $this->actingAs($admin)->postJson($url, ['reason' => 'No le llegó'])->assertOk()->assertJsonPath('data.sent', 'confirm_account');
        Mail::assertSent(ConfirmAccountEmail::class, fn ($m) => $m->hasTo($owner->email));

        $owner->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($admin)->postJson($url, ['reason' => 'No le llegó'])->assertOk()->assertJsonPath('data.sent', 'welcome');
        Mail::assertSent(WelcomeEmail::class, fn ($m) => $m->hasTo($owner->email));
        expect(AdminAuditLog::where('action', 'event.resend_owner_email')->count())->toBe(2);
    });

    it('resends a pending collaborator invite and refuses others', function () {
        Mail::fake();
        $admin = acStaff('superadmin');
        $event = acEvent();
        $mk = fn (array $a = []) => EventCollaborationInvite::create($a + ['event_id' => $event->id, 'email' => 'nuevo@example.com', 'role' => 'editor', 'invited_by_user_id' => User::factory()->create()->id,
            'token' => EventCollaborationInvite::generateToken(), 'status' => 'pending', 'expires_at' => now()->addDay()]);
        $invite = $mk();

        $this->actingAs($admin)->postJson("/api/v1/admin/events/{$event->id}/resend-collaborator-invite/{$invite->id}", ['reason' => 'Lo pidió'])
            ->assertOk()->assertJsonPath('data.resendCount', 1);
        Mail::assertSent(InviteUnregisteredUserMail::class);

        $expired = $mk(['expires_at' => now()->subDay()]);
        $this->actingAs($admin)->postJson("/api/v1/admin/events/{$event->id}/resend-collaborator-invite/{$expired->id}", ['reason' => 'Lo pidió'])->assertUnprocessable();

        $this->actingAs($admin)->getJson("/api/v1/admin/events/{$event->id}/collaborator-invites")->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.status', 'expired');

        $other = acEvent();
        $this->actingAs($admin)->postJson("/api/v1/admin/events/{$other->id}/resend-collaborator-invite/{$invite->id}", ['reason' => 'Lo pidió'])->assertNotFound();
    });
});

describe('slug', function () {
    it('changes a locked slug, warns, and keeps the old one in the audit', function () {
        $event = acEvent(null, 'published');
        $event->update(['custom_url_slug' => 'viejo-enlace']);
        expect($event->isSlugLocked())->toBeTrue();

        $this->actingAs(acStaff('superadmin'))->putJson("/api/v1/admin/events/{$event->id}/slug", ['slug' => 'nuevo-enlace', 'reason' => 'Error de ortografía'])
            ->assertOk()->assertJsonPath('data.slug', 'nuevo-enlace')->assertJsonPath('data.previousSlug', 'viejo-enlace')->assertJsonStructure(['data' => ['warning']]);

        $log = AdminAuditLog::where('action', 'event.change_slug')->first();
        expect($log->before['slug'])->toBe('viejo-enlace')->and($log->after['slug'])->toBe('nuevo-enlace');
    });

    it('validates format, uniqueness and sameness', function () {
        $admin = acStaff('superadmin');
        $event = acEvent();
        $event->update(['custom_url_slug' => 'mio']);
        acEvent()->update(['custom_url_slug' => 'ocupado']);
        $url = "/api/v1/admin/events/{$event->id}/slug";

        foreach (['Mal Formato', 'ocupado', 'mio'] as $slug) {
            $this->actingAs($admin)->putJson($url, ['slug' => $slug, 'reason' => 'motivo valido'])->assertUnprocessable()->assertJsonValidationErrors('slug');
        }
        $this->actingAs($admin)->putJson($url, ['slug' => 'libre'])->assertUnprocessable()->assertJsonValidationErrors('reason');
    });
});

describe('transfer', function () {
    it('moves the ownership, demotes the previous owner to editor and tells both', function () {
        Mail::fake();
        $old = User::factory()->create();
        $new = User::factory()->create();
        $event = acEvent($old);
        EventUserRole::create(['event_id' => $event->id, 'user_id' => $new->id, 'role_id' => Role::where('name', 'viewer')->value('id')]);

        $this->actingAs(acStaff('superadmin'))->postJson("/api/v1/admin/events/{$event->id}/transfer", ['newOwnerEmail' => $new->email, 'reason' => 'Venta de la cuenta'])
            ->assertOk()->assertJsonPath('data.newOwner.id', $new->id)->assertJsonPath('data.previousOwner.role', 'editor');

        $roles = EventUserRole::where('event_id', $event->id)->with('role')->get()->mapWithKeys(fn ($r) => [$r->user_id => $r->role->name]);
        expect($roles[$new->id])->toBe('owner')->and($roles[$old->id])->toBe('editor')->and($roles)->toHaveCount(2);
        Mail::assertSent(OwnershipTransferredMail::class, 2);
        expect(AdminAuditLog::where('action', 'event.transfer_owner')->first()->after['ownerId'])->toBe($new->id);
    });

    it('refuses unknown, suspended and current-owner emails', function () {
        $admin = acStaff('superadmin');
        $owner = User::factory()->create();
        $event = acEvent($owner);
        $suspended = User::factory()->create(['suspended_at' => now()]);
        $url = "/api/v1/admin/events/{$event->id}/transfer";

        foreach (['nadie@example.com', $suspended->email, $owner->email] as $email) {
            $this->actingAs($admin)->postJson($url, ['newOwnerEmail' => $email, 'reason' => 'motivo valido'])->assertUnprocessable()->assertJsonValidationErrors('newOwnerEmail');
        }
        expect(EventUserRole::where('event_id', $event->id)->count())->toBe(1);
    });
});

describe('content', function () {
    it('masks contact data of guests and padrinos and never exposes the invitation code', function () {
        $event = acEvent();
        Guest::factory()->create(['event_id' => $event->id, 'name' => 'Ana', 'email' => 'ana@gmail.com', 'phone' => '+13055550149']);
        Padrino::create(['event_id' => $event->id, 'name' => 'Tío Luis', 'phone' => '+13055551234', 'status' => 'invited']);
        $support = acStaff('support');

        $g = $this->actingAs($support)->getJson("/api/v1/admin/events/{$event->id}/content?section=guests")->assertOk();
        $g->assertJsonPath('data.0.phone', '+1 *** ** 0149')->assertJsonPath('data.0.email', 'a***@gmail.com')->assertJsonMissingPath('data.0.code');
        expect($g->getContent())->not->toContain('3055550149')->not->toContain('ana@gmail.com');

        $this->actingAs($support)->getJson("/api/v1/admin/events/{$event->id}/content?section=padrinos")->assertJsonPath('data.0.phone', '+1 *** ** 1234');
    });

    it('lists comments paginated with the ids the moderation API needs, and validates the section', function () {
        $event = acEvent();
        $guest = Guest::factory()->create(['event_id' => $event->id, 'name' => 'Ana']);
        foreach (range(1, 7) as $i) {
            EventComment::create(['event_id' => $event->id, 'authorable_type' => $guest->getMorphClass(), 'authorable_id' => $guest->id, 'comment' => "Hola $i"]);
        }
        $support = acStaff('support');

        $this->actingAs($support)->getJson("/api/v1/admin/events/{$event->id}/content?section=comments&perPage=5")
            ->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('meta.total', 7)->assertJsonPath('meta.lastPage', 2)
            ->assertJsonPath('data.0.author.name', 'Ana')->assertJsonPath('data.0.type', 'comment');
        $this->actingAs($support)->getJson("/api/v1/admin/events/{$event->id}/content?section=otra")->assertUnprocessable();
    });

    it('does not run more queries when the page has more rows', function () {
        $event = acEvent();
        $support = acStaff('support');
        $count = function () use ($event, $support) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($support)->getJson("/api/v1/admin/events/{$event->id}/content?section=comments")->assertOk();

            return count(DB::getQueryLog());
        };
        $mk = function (int $n) use ($event) {
            foreach (range(1, $n) as $i) {
                $g = Guest::factory()->create(['event_id' => $event->id]);
                EventComment::create(['event_id' => $event->id, 'authorable_type' => $g->getMorphClass(), 'authorable_id' => $g->id, 'comment' => 'x']);
            }
        };
        $mk(1);
        $count(); // warms the permission cache
        $few = $count();
        $mk(8);
        expect($count())->toBe($few);
    });
});

describe('staff log', function () {
    it('shows the staff actions on that event to superadmin only', function () {
        $admin = acStaff('superadmin');
        $event = acEvent();
        $this->actingAs($admin)->postJson("/api/v1/admin/events/{$event->id}/publish", ['reason' => 'Pedido de la dueña'])->assertOk();

        $this->actingAs($admin)->getJson("/api/v1/admin/events/{$event->id}/log?source=staff")
            ->assertOk()->assertJsonPath('data.0.action', 'event.publish')->assertJsonPath('data.0.reason', 'Pedido de la dueña')->assertJsonPath('data.0.actor.id', $admin->id);
    });
});
