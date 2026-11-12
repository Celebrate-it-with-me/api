<?php

use App\Models\EventActivity;
use App\Models\Events;
use App\Models\EventType;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

it('shows the recent activity of an event the app just changed', function () {
    $owner = User::factory()->create();
    $created = $this->actingAs($owner)->postJson('/api/v1/app/event', [
        'eventName' => 'Mis XV', 'eventType' => EventType::where('slug', 'quinceanera')->value('id'), 'startDate' => '12/01/2027 18:00',
    ])->assertSuccessful();
    $id = $created->json('data.id');
    $this->actingAs($owner)->patchJson("/api/v1/app/event/{$id}", ['eventName' => 'Mis XV 2'])->assertOk();
    $this->actingAs($owner)->postJson("/api/v1/app/event/{$id}/publish")->assertOk();

    $response = $this->actingAs($owner)->getJson("/api/v1/app/event/{$id}/dashboard-logs")->assertOk();

    expect($response->json('data'))->toHaveCount(3)
        ->and($response->json('data.0.message'))->toContain($owner->name);
});

it('stores the actor with its morph alias, not the class name', function () {
    $owner = User::factory()->create();
    $event = Events::factory()->create(['event_type_id' => EventType::where('slug', 'quinceanera')->value('id')]);

    $activity = EventActivity::logActivity($event->id, 'event_updated', $owner, $event, ['event_name' => 'x']);

    expect($activity->actor_type)->toBe('user')->and($activity->actor->is($owner))->toBeTrue();
});
