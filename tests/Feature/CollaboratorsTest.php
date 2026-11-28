<?php

use App\Mail\InviteRegisteredUserMail;
use App\Mail\InviteUnregisteredUserMail;
use App\Models\EventCollaborationInvite;
use App\Models\Events;
use App\Models\EventUserRole;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Mail;

require_once __DIR__ . '/EventAccessTest.php';

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

function collabApi(Events $event, string $path = ''): string
{
    return "/api/v1/app/event/{$event->id}/collaborators{$path}";
}

function pendingInvite(Events $event, User $by, array $attrs = []): EventCollaborationInvite
{
    $invite = EventCollaborationInvite::create(Arr::except($attrs, 'sent_at') + [
        'event_id' => $event->id, 'email' => 'nueva@x.test', 'role' => 'viewer', 'token' => EventCollaborationInvite::generateToken(),
        'status' => 'pending', 'invited_by_user_id' => $by->id, 'expires_at' => now()->addDays(7),
    ]);

    // `sent_at` is not mass assignable.
    if (isset($attrs['sent_at'])) {
        $invite->forceFill(['sent_at' => $attrs['sent_at']])->save();
    }

    return $invite;
}

it('lists members and open invitations in the screen contract', function () {
    [$event, $m] = eventWithMembers();
    pendingInvite($event, $m['owner']);
    pendingInvite($event, $m['owner'], ['email' => 'vieja@x.test', 'expires_at' => now()->subDay()]);
    pendingInvite($event, $m['owner'], ['email' => 'ok@x.test', 'status' => 'accepted']);

    $rows = $this->actingAs($m['owner'])->getJson(collabApi($event))->assertOk()->json('data');

    expect($rows)->toHaveCount(5);
    $owner = collect($rows)->firstWhere('role', 'owner');
    expect($owner)->toMatchArray(['type' => 'member', 'user_id' => $m['owner']->id, 'email' => $m['owner']->email, 'status' => 'active'])
        ->and($owner)->toHaveKeys(['id', 'name', 'avatar_url']);
    $invites = collect($rows)->where('type', 'invite')->keyBy('email');
    expect($invites)->toHaveCount(2)
        ->and($invites['nueva@x.test']['status'])->toBe('pending')
        ->and($invites['vieja@x.test']['status'])->toBe('expired')
        ->and($invites['nueva@x.test'])->not->toHaveKey('token');
});

it('hides the team from strangers and other events', function () {
    [$event, $m] = eventWithMembers();
    $this->actingAs($m['stranger'])->getJson(collabApi($event))->assertNotFound();
    $this->actingAs($m['editor'])->getJson(collabApi($event))->assertForbidden();
    $this->actingAs($m['viewer'])->getJson(collabApi($event))->assertForbidden();
});

it('invites a new email with a pending invitation and a mail', function () {
    Mail::fake();
    [$event, $m] = eventWithMembers();

    $this->actingAs($m['owner'])->postJson(collabApi($event), ['email' => 'Nueva@X.test', 'role' => 'editor'])
        ->assertOk()->assertJsonPath('data.type', 'invite')->assertJsonPath('data.status', 'pending')->assertJsonPath('data.role', 'editor');

    expect(EventCollaborationInvite::where('event_id', $event->id)->where('email', 'nueva@x.test')->where('status', 'pending')->exists())->toBeTrue();
    Mail::assertSent(InviteUnregisteredUserMail::class);

    $this->actingAs($m['owner'])->postJson(collabApi($event), ['email' => 'nueva@x.test', 'role' => 'viewer'])->assertUnprocessable();
});

it('adds an existing account straight away', function () {
    Mail::fake();
    [$event, $m] = eventWithMembers();
    $lucia = User::factory()->create();

    $this->actingAs($m['owner'])->postJson(collabApi($event), ['email' => $lucia->email, 'role' => 'viewer'])
        ->assertOk()->assertJsonPath('data.type', 'member')->assertJsonPath('data.user_id', $lucia->id)->assertJsonPath('data.role', 'viewer');

    expect($lucia->hasEventRole($event, 'viewer'))->toBeTrue();
    Mail::assertSent(InviteRegisteredUserMail::class);
    $this->actingAs($m['owner'])->postJson(collabApi($event), ['email' => $lucia->email, 'role' => 'viewer'])->assertUnprocessable();
});

it('refuses to invite the owner, a bad role or without permission', function () {
    [$event, $m] = eventWithMembers();

    $this->actingAs($m['owner'])->postJson(collabApi($event), ['email' => $m['owner']->email, 'role' => 'editor'])->assertUnprocessable();
    $this->actingAs($m['owner'])->postJson(collabApi($event), ['email' => 'a@x.test', 'role' => 'owner'])->assertUnprocessable();
    $this->actingAs($m['editor'])->postJson(collabApi($event), ['email' => 'a@x.test', 'role' => 'viewer'])->assertForbidden();
    $this->actingAs($m['stranger'])->postJson(collabApi($event), ['email' => 'a@x.test', 'role' => 'viewer'])->assertNotFound();
});

it('changes the role of a member', function () {
    [$event, $m] = eventWithMembers();

    $this->actingAs($m['owner'])->patchJson(collabApi($event, "/{$m['viewer']->id}"), ['role' => 'editor'])
        ->assertOk()->assertJsonPath('data.role', 'editor');
    expect($m['viewer']->hasEventRole($event, 'editor'))->toBeTrue();

    $this->actingAs($m['owner'])->patchJson(collabApi($event, "/{$m['viewer']->id}"), ['role' => 'owner'])->assertUnprocessable();
    $this->actingAs($m['owner'])->patchJson(collabApi($event, "/{$m['owner']->id}"), ['role' => 'viewer'])->assertForbidden();
    $this->actingAs($m['owner'])->patchJson(collabApi($event, "/{$m['stranger']->id}"), ['role' => 'viewer'])->assertNotFound();
    $this->actingAs($m['editor'])->patchJson(collabApi($event, "/{$m['viewer']->id}"), ['role' => 'viewer'])->assertForbidden();
    $this->actingAs($m['stranger'])->patchJson(collabApi($event, "/{$m['viewer']->id}"), ['role' => 'viewer'])->assertNotFound();
});

it('removes a member but never the owner', function () {
    [$event, $m] = eventWithMembers();
    $m['viewer']->update(['last_active_event_id' => $event->id]);

    $this->actingAs($m['editor'])->deleteJson(collabApi($event, "/{$m['viewer']->id}"))->assertForbidden();
    $this->actingAs($m['owner'])->deleteJson(collabApi($event, "/{$m['owner']->id}"))->assertForbidden();
    $this->actingAs($m['owner'])->deleteJson(collabApi($event, "/{$m['stranger']->id}"))->assertNotFound();
    $this->actingAs($m['owner'])->deleteJson(collabApi($event, "/{$m['viewer']->id}"))->assertOk();

    expect($m['viewer']->hasEventRole($event))->toBeFalse()
        ->and($m['viewer']->fresh()->last_active_event_id)->toBeNull()
        ->and($m['owner']->hasEventRole($event, 'owner'))->toBeTrue();
});

it('does not touch the team of another event', function () {
    [$eventA, $a] = eventWithMembers();
    [$eventB, $b] = eventWithMembers();
    $invite = pendingInvite($eventB, $b['owner']);

    $this->actingAs($a['owner'])->deleteJson(collabApi($eventA, "/{$b['viewer']->id}"))->assertNotFound();
    $this->actingAs($a['owner'])->postJson(collabApi($eventA, "/invite/{$invite->id}/resend"))->assertNotFound();
    $this->actingAs($a['owner'])->deleteJson(collabApi($eventA, "/invite/{$invite->id}"))->assertNotFound();
    expect(EventUserRole::where('event_id', $eventB->id)->count())->toBe(3)->and($invite->fresh())->not->toBeNull();
});

it('resends a pending or expired invitation and renews it', function () {
    Mail::fake();
    [$event, $m] = eventWithMembers();
    $expired = pendingInvite($event, $m['owner'], ['expires_at' => now()->subDay()]);

    $this->actingAs($m['editor'])->postJson(collabApi($event, "/invite/{$expired->id}/resend"))->assertForbidden();
    $this->actingAs($m['owner'])->postJson(collabApi($event, "/invite/{$expired->id}/resend"))
        ->assertOk()->assertJsonPath('data.status', 'pending');

    $fresh = $expired->fresh();
    expect($fresh->resend_count)->toBe(1)->and($fresh->expires_at->isFuture())->toBeTrue();
    Mail::assertSent(InviteUnregisteredUserMail::class, 1);
});

it('resends a declined invitation: back to pending with a new token and a new expiry', function () {
    Mail::fake();
    [$event, $m] = eventWithMembers();
    $declined = pendingInvite($event, $m['owner'], ['email' => 'no@x.test', 'status' => 'declined', 'expires_at' => now()->subDay(), 'sent_at' => now()->subDays(10)]);
    $oldToken = $declined->token;

    $this->actingAs($m['editor'])->postJson(collabApi($event, "/invite/{$declined->id}/resend"))->assertForbidden();
    $this->actingAs($m['owner'])->postJson(collabApi($event, "/invite/{$declined->id}/resend"))
        ->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.declinedAt', null);

    $fresh = $declined->fresh();
    expect($fresh->status)->toBe('pending')->and($fresh->token)->not->toBe($oldToken)
        ->and($fresh->resend_count)->toBe(1)->and($fresh->expires_at->isFuture())->toBeTrue()->and($fresh->sent_at->isToday())->toBeTrue();
    $this->getJson("/api/v1/app/event/{$event->id}/collaborators/invite/{$oldToken}")->assertStatus(422);
});

it('keeps refusing to resend an accepted invitation', function () {
    [$event, $m] = eventWithMembers();
    $accepted = pendingInvite($event, $m['owner'], ['status' => 'accepted']);

    $this->actingAs($m['owner'])->postJson(collabApi($event, "/invite/{$accepted->id}/resend"))->assertNotFound();
});

it('lists when each invitation was sent and when it was declined, in ISO 8601', function () {
    [$event, $m] = eventWithMembers();
    $sent = now()->subDays(3)->startOfSecond();
    pendingInvite($event, $m['owner'], ['email' => 'p@x.test', 'sent_at' => $sent]);
    $declined = pendingInvite($event, $m['owner'], ['email' => 'd@x.test', 'status' => 'declined', 'sent_at' => $sent]);
    $declined->forceFill(['updated_at' => $sent->copy()->addDay()])->saveQuietly();

    $rows = collect($this->actingAs($m['owner'])->getJson(collabApi($event))->assertOk()->json('data'))->where('type', 'invite')->keyBy('email');

    expect($rows['p@x.test']['sentAt'])->toBe($sent->toIso8601String())->and($rows['p@x.test']['declinedAt'])->toBeNull()
        ->and($rows['d@x.test']['sentAt'])->toBe($sent->toIso8601String())->and($rows['d@x.test']['declinedAt'])->toBe($sent->copy()->addDay()->toIso8601String());
});

it('cancels an invitation', function () {
    [$event, $m] = eventWithMembers();
    $invite = pendingInvite($event, $m['owner']);
    $accepted = pendingInvite($event, $m['owner'], ['email' => 'ok@x.test', 'status' => 'accepted']);

    $this->actingAs($m['viewer'])->deleteJson(collabApi($event, "/invite/{$invite->id}"))->assertForbidden();
    $this->actingAs($m['owner'])->deleteJson(collabApi($event, "/invite/{$accepted->id}"))->assertNotFound();
    $this->actingAs($m['owner'])->deleteJson(collabApi($event, "/invite/{$invite->id}"))->assertOk();
    expect(EventCollaborationInvite::find($invite->id))->toBeNull();
    $this->actingAs($m['owner'])->deleteJson(collabApi($event, "/invite/{$invite->id}"))->assertNotFound();
});
