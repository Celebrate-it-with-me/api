<?php

use App\Models\EventCollaborationInvite;
use App\Models\EventFeature;
use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\EventUserRole;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

function collabEvent(): array
{
    $event = Events::factory()->draft()->create([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', 'premium')->value('id'),
    ]);
    EventFeature::create(['event_id' => $event->id]);
    $owner = User::factory()->create();
    EventUserRole::create(['event_id' => $event->id, 'user_id' => $owner->id, 'role_id' => Role::where('name', 'owner')->value('id')]);

    return [$event, $owner, "/api/v1/app/event/{$event->id}/collaborators"];
}

function collabInvite(Events $event, User $owner, array $attrs = []): EventCollaborationInvite
{
    return EventCollaborationInvite::create($attrs + [
        'event_id' => $event->id, 'email' => 'guy@example.com', 'role' => 'editor', 'token' => (string) Str::uuid(),
        'status' => 'pending', 'invited_by_user_id' => $owner->id, 'expires_at' => now()->addDays(3),
    ]);
}

it('only invites editors or viewers (S-08)', function () {
    [, $owner, $url] = collabEvent();

    foreach (['superadmin', 'owner', 'nonsense'] as $role) {
        $this->actingAs($owner)->postJson($url, ['email' => 'new@example.com', 'role' => $role])->assertStatus(422);
    }
    expect(EventCollaborationInvite::count())->toBe(0);
});

it('adds an existing account without an SQL error (S-08)', function () {
    [$event, $owner, $url] = collabEvent();
    $guy = User::factory()->create();

    $this->actingAs($owner)->postJson($url, ['email' => $guy->email, 'role' => 'viewer'])->assertOk();

    expect(EventUserRole::where('event_id', $event->id)->where('user_id', $guy->id)->exists())->toBeTrue();
    $this->actingAs($owner)->postJson($url, ['email' => $guy->email, 'role' => 'viewer'])->assertUnprocessable();
});

it('does not accept an expired, declined or used invitation (S-08)', function () {
    [$event, $owner] = collabEvent();
    $guy = User::factory()->create(['email' => 'guy@example.com']);

    foreach ([['status' => 'declined', 'expires_at' => now()->subDays(30)], ['status' => 'pending', 'expires_at' => now()->subMinute()], ['status' => 'accepted'], ['status' => 'declined']] as $state) {
        $inv = collabInvite($event, $owner, $state);
        $this->actingAs($guy)->postJson("/api/v1/app/event/{$event->id}/collaborators/invite/{$inv->id}/accept")->assertNotFound();
    }
    expect(EventUserRole::where('user_id', $guy->id)->exists())->toBeFalse();
});

it('accepts a pending invitation once, only for its email', function () {
    [$event, $owner] = collabEvent();
    $guy = User::factory()->create(['email' => 'guy@example.com']);
    $other = User::factory()->create();
    $inv = collabInvite($event, $owner);

    $this->actingAs($other)->postJson("/api/v1/app/event/{$event->id}/collaborators/invite/{$inv->id}/accept")->assertStatus(400);
    $this->actingAs($guy)->postJson("/api/v1/app/event/{$event->id}/collaborators/invite/{$inv->id}/accept")->assertOk();
    $this->actingAs($guy)->postJson("/api/v1/app/event/{$event->id}/collaborators/invite/{$inv->id}/accept")->assertNotFound();
});

it('lets the owner remove a collaborator, but never the owner (S-08)', function () {
    [$event, $owner, $url] = collabEvent();
    $guy = User::factory()->create();
    EventUserRole::create(['event_id' => $event->id, 'user_id' => $guy->id, 'role_id' => Role::where('name', 'editor')->value('id')]);
    $stranger = User::factory()->create();

    $this->actingAs($stranger)->deleteJson("$url/{$guy->id}")->assertNotFound();
    $this->actingAs($guy)->deleteJson("$url/{$owner->id}")->assertForbidden();
    $this->actingAs($owner)->deleteJson("$url/{$owner->id}")->assertForbidden();
    $this->actingAs($owner)->deleteJson("$url/{$guy->id}")->assertOk();

    expect(EventUserRole::where('event_id', $event->id)->where('user_id', $guy->id)->exists())->toBeFalse();
    $this->actingAs($guy)->getJson("/api/v1/app/event/{$event->id}/dress-code")->assertNotFound();
    $this->actingAs($owner)->deleteJson("$url/{$guy->id}")->assertNotFound();
});

it('only reveals an invitation through its own event (S-08)', function () {
    [$event, $owner] = collabEvent();
    [$other] = collabEvent();
    $inv = collabInvite($event, $owner);

    $this->getJson("/api/v1/app/event/{$event->id}/collaborators/invite/{$inv->token}")->assertOk();
    $this->getJson("/api/v1/app/event/{$other->id}/collaborators/invite/{$inv->token}")->assertStatus(422);
});
