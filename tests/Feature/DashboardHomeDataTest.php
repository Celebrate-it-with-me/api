<?php

use App\Http\Services\Budget\BudgetService;
use App\Models\BudgetItem;
use App\Models\EventActivity;
use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\EventUserRole;
use App\Models\Guest;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

function homeEvent(): array
{
    $event = Events::factory()->create([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', 'premium')->value('id'),
        'status' => 'published',
    ]);
    $owner = User::factory()->create();
    EventUserRole::create(['event_id' => $event->id, 'user_id' => $owner->id, 'role_id' => Role::where('name', 'owner')->value('id')]);

    return [$event, $owner];
}

function feedFor($test, Events $event, User $owner, string $lang): array
{
    return $test->actingAs($owner)->getJson("/api/v1/app/event/{$event->id}/dashboard-logs?lang={$lang}")->assertOk()->json('data');
}

dataset('feed sentences', [
    'template' => ['invitation_template_changed', ['template' => 'Clásica · Quinceañera'], 'Marisol Fernández', 'Marisol Fernández cambió la plantilla a Clásica · Quinceañera', 'Invitation template changed to Clásica · Quinceañera by Marisol Fernández'],
    'song' => ['music_suggested', ['name' => 'Rosa Fernández', 'song' => 'Carnicero'], null, 'Rosa Fernández sugirió la canción «Carnicero»', 'Guest Rosa Fernández suggested the song "Carnicero"'],
    'opened' => ['invitation_opened', ['name' => 'Rosa Fernández'], null, 'Rosa Fernández abrió su invitación', 'Guest Rosa Fernández opened their invitation'],
]);

it('writes the feed sentence in the request language, from type and params (nothing is stored as text)', function (string $type, array $payload, ?string $actorName, string $es, string $en) {
    [$event, $owner] = homeEvent();
    if ($actorName) {
        $owner->forceFill(['name' => $actorName])->save();
    }
    $activity = EventActivity::logActivity($event->id, $type, $actorName ? $owner : null, null, $payload);

    $spanish = feedFor($this, $event, $owner, 'es')[0];
    expect(rtrim($spanish['message'], '.'))->toBe($es)
        ->and($spanish['type'])->toBe($type)
        ->and($spanish['params'])->toHaveKeys(['actor', 'name', 'template', 'song', 'count', 'companions', 'status'])
        ->and($spanish['occurredAt'])->toBe($activity->created_at->utc()->format('Y-m-d\TH:i:s\Z'));

    expect(rtrim(feedFor($this, $event, $owner, 'en')[0]['message'], '.'))->toBe($en);
    expect(array_keys($activity->fresh()->getAttributes()))->not->toContain('message');
})->with('feed sentences');

it('gives the params of each kind of sentence and the relative time in Spanish', function () {
    [$event, $owner] = homeEvent();
    EventActivity::logActivity($event->id, 'guests_imported', $owner, null, ['count' => 12]);
    EventActivity::logActivity($event->id, 'guest_confirmed', null, null, ['name' => 'Rosa', 'companions' => 2]);
    EventActivity::logActivity($event->id, 'guest_rsvp_changed', null, null, ['name' => 'Rosa', 'to' => 'not-attending']);
    EventActivity::query()->update(['created_at' => now()->subDay()]);

    $rows = collect(feedFor($this, $event, $owner, 'es'))->keyBy('type');
    expect($rows['guests_imported']['params']['count'])->toBe(12)
        ->and($rows['guest_confirmed']['params']['companions'])->toBe(2)
        ->and($rows['guest_confirmed']['message'])->toContain('2 acompañantes')
        ->and($rows['guest_rsvp_changed']['params']['status'])->toBe('not-attending')
        ->and($rows['guest_rsvp_changed']['message'])->toContain('no asiste')
        ->and($rows['guests_imported']['createdAt'])->toBe('hace 1 día');
});

it('does not fail on old activities with missing params', function () {
    [$event, $owner] = homeEvent();
    EventActivity::logActivity($event->id, 'music_suggested', null, null, []);

    $row = feedFor($this, $event, $owner, 'es')[0];
    expect($row['params']['song'])->toBeNull()->and($row['params']['actor'])->toBe('Sistema')->and($row['message'])->toBeString();
});

it('measures setup progress on configuration, not on how many guests answered', function () {
    [$event, $owner] = homeEvent();
    Guest::factory()->count(7)->create(['event_id' => $event->id, 'rsvp_status' => 'pending', 'parent_id' => null]);

    $withPending = Events::withResourceData()->find($event->id);
    $rsvpDone = ! in_array('rsvp', $withPending->pendingSetupModules(), true);
    expect($rsvpDone)->toBeTrue();

    Guest::query()->where('event_id', $event->id)->update(['rsvp_status' => 'attending']);
    expect(Events::withResourceData()->find($event->id)->calculateSetupProgress())->toBe($withPending->calculateSetupProgress());

    Guest::query()->where('event_id', $event->id)->delete();
    $empty = Events::withResourceData()->find($event->id);
    expect($empty->pendingSetupModules())->toContain('rsvp')->and($empty->calculateSetupProgress())->toBeLessThan($withPending->calculateSetupProgress());

    $json = $this->actingAs($owner)->getJson("/api/v1/app/event/{$event->id}");
    expect($json->json('data.setupProgressKind'))->toBe('configuration');
});

it('tells how far the estimate and the payments go over the cap, in cents', function () {
    [$event, $owner] = homeEvent();
    $budget = app(BudgetService::class)->budgetFor($event);
    $budget->update(['budget_cap' => 18000]);
    BudgetItem::query()->create(['event_budget_id' => $budget->id, 'title' => 'Salón', 'estimated_cost' => 18530.10]);

    $totals = $this->actingAs($owner)->getJson("/api/v1/app/event/{$event->id}/budget")->assertOk()->json('data.totals');
    expect($totals['estimatedOverCap'])->toBeTrue()
        ->and($totals['estimatedOverByCents'])->toBe(53010)
        ->and($totals['estimatedOverBy'])->toBe(530.1)
        ->and($totals['overCap'])->toBeTrue()
        ->and($totals['overByCents'])->toBe(53010)
        ->and($totals['paidOverCap'])->toBeFalse()
        ->and($totals['paidOverByCents'])->toBe(0);
});

it('compares against the cap without float noise and says nothing without a cap', function () {
    $service = app(BudgetService::class);
    $totals = ['estimated' => 18000.0, 'owed' => 18000.0, 'paid' => 18000.01, 'remaining' => 0.0];

    $equal = $service->capComparison(18000.0, $totals);
    expect($equal['estimatedOverCap'])->toBeFalse()->and($equal['paidOverCap'])->toBeTrue()->and($equal['paidOverByCents'])->toBe(1);

    $none = $service->capComparison(null, $totals);
    expect($none['estimatedOverCap'])->toBeFalse()->and($none['overBy'])->toBe(0)->and($none['paidOverCap'])->toBeFalse();
});

it('says the assistant progress measures the plan', function () {
    [$event, $owner] = homeEvent();

    $this->actingAs($owner)->getJson("/api/v1/app/event/{$event->id}/assistant/next")->assertOk()->assertJsonPath('data.progress.kind', 'plan');
});
