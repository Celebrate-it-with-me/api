<?php

use App\Mail\InviteRegisteredUserMail;
use App\Mail\InviteUnregisteredUserMail;
use App\Models\EventCollaborationInvite;
use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\EventUserRole;
use App\Models\User;
use App\Models\UserPreference;
use App\Support\AdminPermissions;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;

/*
 * Accounts are matched on lower(email) (unique index). Every lookup by an address typed by a person or stored in an invite
 * must find the account whatever the case of the letters.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    User::role(AdminPermissions::STAFF_ROLES)->get()->each(fn (User $u) => $u->syncRoles([]));
});

/** An account stored with capitals (as legacy rows are), bypassing the lowercasing at sign-up. */
function ecUser(string $stored, array $attrs = []): User
{
    $user = User::factory()->create($attrs);
    DB::table('users')->where('id', $user->id)->update(['email' => $stored]);

    return $user->fresh();
}

function ecInvite(string $email, ?Events $event = null, array $attrs = []): EventCollaborationInvite
{
    $event ??= Events::factory()->draft()->create([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', 'basic')->value('id'),
    ]);

    return EventCollaborationInvite::create($attrs + ['event_id' => $event->id, 'email' => $email, 'role' => 'editor', 'invited_by_user_id' => User::factory()->create()->id,
        'token' => EventCollaborationInvite::generateToken(), 'status' => 'pending', 'expires_at' => now()->addDay()]);
}

it('opens the admin panel login whatever the case of the typed address', function () {
    $staff = ecUser('Staff.Person@Example.com', ['password' => 'old-password-123']);
    $staff->assignRole('superadmin');

    $this->postJson('/api/v1/admin/login', ['email' => 'staff.person@example.com', 'password' => 'old-password-123', 'device' => 'x'])->assertOk();
    $this->postJson('/api/v1/admin/login', ['email' => 'STAFF.PERSON@EXAMPLE.COM', 'password' => 'old-password-123', 'device' => 'x'])->assertOk();
});

it('resets the password from the console whatever the case of the address', function () {
    $user = ecUser('Reset.Me@Example.com');

    $this->artisan('cwm:force-reset-password', ['email' => 'reset.me@example.com'])
        ->expectsOutputToContain('User found')
        ->expectsQuestion('Please enter the new password', 'brand-new-pass-1')
        ->expectsQuestion('Please repeat the password to confirm', 'brand-new-pass-1');

    expect(password_verify('brand-new-pass-1', $user->fresh()->password))->toBeTrue();
});

it('resets the two-factor of a staff member from the console whatever the case of the address', function () {
    ecUser('Staff.Two@Example.com');

    $this->artisan('admin:two-factor-reset', ['email' => 'staff.two@example.com'])->assertSuccessful();
});

it('tells a registered invitee from an unknown one in the pending-invites commands whatever the case', function () {
    Mail::fake();
    ecUser('Invited.Person@Example.com');
    ecInvite('invited.person@example.com');
    ecInvite('nobody@example.com');

    $this->artisan('invites:send-initial')->assertSuccessful();

    Mail::assertSent(InviteRegisteredUserMail::class, fn ($m) => $m->hasTo('invited.person@example.com'));
    Mail::assertSent(InviteUnregisteredUserMail::class, fn ($m) => $m->hasTo('nobody@example.com'));
});

it('resends pending invites to a registered invitee whatever the case', function () {
    Mail::fake();
    ecUser('Invited.Again@Example.com');
    ecInvite('INVITED.AGAIN@example.com')->forceFill(['sent_at' => now()->subDays(2), 'resend_count' => 0])->save();

    $this->artisan('invites:resend')->assertSuccessful();

    Mail::assertSent(InviteRegisteredUserMail::class);
    Mail::assertNotSent(InviteUnregisteredUserMail::class);
});

it('writes the invitation mail in the language of an account stored with other capitals', function () {
    $user = ecUser('Lang.User@Example.com');
    UserPreference::query()->updateOrCreate(['user_id' => $user->id], ['language' => 'en']);

    expect((new InviteRegisteredUserMail(ecInvite('lang.user@example.com')))->locale)->toBe('en');
});

it('sends the support resend of an invite as a registered-user mail whatever the case', function () {
    Mail::fake();
    $admin = User::factory()->create();
    $admin->assignRole('superadmin');
    ecUser('Collab.Person@Example.com');
    $owner = User::factory()->create();
    $event = Events::factory()->draft()->create([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', 'basic')->value('id'),
    ]);
    EventUserRole::create(['event_id' => $event->id, 'user_id' => $owner->id, 'role_id' => Role::where('name', 'owner')->value('id')]);
    $invite = ecInvite('collab.person@example.com', $event);

    $this->actingAsReauthed($admin)->postJson("/api/v1/admin/events/{$event->id}/resend-collaborator-invite/{$invite->id}", ['reason' => 'Lo pidió'])->assertOk();

    Mail::assertSent(InviteRegisteredUserMail::class);
    Mail::assertNotSent(InviteUnregisteredUserMail::class);
});

it('finds the new owner of a transfer whatever the case of the typed address', function () {
    Mail::fake();
    $admin = User::factory()->create();
    $admin->assignRole('superadmin');
    $new = ecUser('New.Owner@Example.com');
    $event = Events::factory()->draft()->create([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', 'basic')->value('id'),
    ]);
    EventUserRole::create(['event_id' => $event->id, 'user_id' => User::factory()->create()->id, 'role_id' => Role::where('name', 'owner')->value('id')]);

    $this->actingAsReauthed($admin)->postJson("/api/v1/admin/events/{$event->id}/transfer", ['newOwnerEmail' => 'new.owner@example.com', 'reason' => 'Venta de la cuenta'])
        ->assertOk()->assertJsonPath('data.newOwner.id', $new->id);
});
