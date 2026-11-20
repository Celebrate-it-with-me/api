<?php

use App\Http\Services\AppServices\EventModules;
use App\Http\Services\Assistant\AssistantService;
use App\Models\AssistantCustomTask;
use App\Models\AssistantTask;
use App\Models\AssistantTaskState;
use App\Models\BudgetCategory;
use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\EventUserRole;
use App\Models\Guest;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use App\Support\AdminPermissions;
use Carbon\CarbonImmutable;
use Database\Seeders\AssistantTaskSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->withoutMiddleware(ThrottleRequests::class);
});

/** @return array{0: Events, 1: array<string, User>, 2: string} */
function assistantEvent(string $type = 'quinceanera', array $extra = []): array
{
    $event = Events::factory()->draft()->create(array_merge([
        'event_type_id' => EventType::where('slug', $type)->value('id'),
        'event_plan_id' => EventPlan::orderBy('id')->value('id'),
        'start_date' => now()->addMonths(11),
        'created_at' => now()->subYears(2),
        'locale' => 'es',
    ], $extra));
    $users = [];
    foreach (['owner', 'editor', 'viewer'] as $role) {
        $users[$role] = User::factory()->create();
        EventUserRole::create(['event_id' => $event->id, 'user_id' => $users[$role]->id, 'role_id' => Role::where('name', $role)->value('id')]);
    }
    $users['stranger'] = User::factory()->create();

    return [$event, $users, "/api/v1/app/event/{$event->id}/assistant"];
}

describe('reading', function () {
    it('lets owner, editor and viewer read, and hides the event from strangers', function () {
        [, $u, $url] = assistantEvent();

        foreach (['owner', 'editor', 'viewer'] as $role) {
            $this->actingAs($u[$role])->getJson("$url/next")->assertOk()->assertJsonPath('data.mode', 'normal');
            $this->actingAs($u[$role])->getJson("$url/plan")->assertOk()->assertJsonPath('data.mode', 'normal');
        }
        $this->actingAs($u['stranger'])->getJson("$url/next")->assertNotFound();
        $this->actingAs($u['stranger'])->getJson("$url/plan")->assertNotFound();
    });

    it('needs a signed-in user', function () {
        [, , $url] = assistantEvent();

        $this->getJson("$url/next")->assertUnauthorized();
        $this->postJson("$url/tasks/budget_cap/done")->assertUnauthorized();
    });

    it('answers the next step in the contract shape, in the language asked for or the account\'s', function () {
        [, $u, $url] = assistantEvent();

        $es = $this->actingAs($u['owner'])->getJson("$url/next")->assertOk()->json('data');
        expect($es)->toHaveKeys(['mode', 'monthsLeft', 'daysLeft', 'steps', 'allSet', 'progress'])
            ->and($es['progress'])->toHaveKeys(['done', 'total', 'ratio'])
            ->and($es['allSet'])->toBeFalse()
            ->and($es['steps'])->not->toBeEmpty()
            ->and(count($es['steps']))->toBeLessThanOrEqual(3);
        $step = $es['steps'][0];
        expect($step)->toHaveKeys(['key', 'phase', 'title', 'state', 'reason', 'reasonCode', 'reasonParams', 'route', 'moduleKey', 'moduleLocked', 'doneBy', 'blockedBy', 'snoozedUntil', 'startMonths', 'lateMonths'])
            ->and($step['key'])->toBe('budget_cap')->and($step['title'])->toBe('Define cuánto quieres gastar')
            ->and($step['state'])->toBe('next')->and($step['reason'])->not->toContain('{');

        $en = $this->actingAs($u['owner'])->getJson("$url/next?lang=en")->json('data.steps.0');
        expect($en['title'])->toBe('Set your budget');

        $u['owner']->preferences()->updateOrCreate([], ['language' => 'en']);
        expect($this->actingAs($u['owner']->fresh())->getJson("$url/next")->json('data.steps.0.title'))->toBe('Set your budget');
        expect($this->actingAs($u['owner']->fresh())->getJson("$url/next?lang=es")->json('data.steps.0.title'))->toBe('Define cuánto quieres gastar');
    });

    it('lays the plan out by phase with the labels translated, done last', function () {
        [, $u, $url] = assistantEvent();
        $this->actingAs($u['owner'])->postJson("$url/tasks/choose_padrinos/done")->assertOk();

        $plan = $this->actingAs($u['owner'])->getJson("$url/plan")->assertOk()->json('data');

        expect(collect($plan['phases'])->pluck('key')->all())->toBe(['foundation', 'planning', 'details', 'invitations', 'final', 'after'])
            ->and(collect($plan['phases'])->pluck('label')->all())->toBe(['Primeros pasos', 'Reservas', 'Detalles', 'Invitaciones', 'Recta final', 'Después'])
            ->and($plan)->toHaveKeys(['mode', 'monthsLeft', 'daysLeft', 'progress', 'phases', 'overdue', 'hidden', 'custom']);
        $foundation = collect($plan['phases'][0]['tasks']);
        expect($foundation->last()['key'])->toBe('choose_padrinos')->and($foundation->last()['state'])->toBe('done');
        $en = $this->actingAs($u['owner'])->getJson("$url/plan?lang=en")->json('data.phases.*.label');
        expect($en)->toBe(['First steps', 'Bookings', 'Details', 'Invitations', 'Final stretch', 'Afterwards']);
    });

    it('offers only the "afterwards" phase once the date has passed, and nothing for a kind of event it does not plan', function () {
        [, $u, $url] = assistantEvent('quinceanera', ['start_date' => now()->subDays(10), 'created_at' => now()->subMonths(8)]);

        $plan = $this->actingAs($u['owner'])->getJson("$url/plan")->assertOk()->json('data');
        expect($plan['mode'])->toBe('event_past')->and(collect($plan['phases'])->pluck('key')->all())->toBe(['after']);
        $next = $this->actingAs($u['owner'])->getJson("$url/next")->json('data');
        expect(collect($next['steps'])->pluck('phase')->unique()->all())->toBe(['after']);

        [, $o, $ourl] = assistantEvent('quinceanera');
        $birthday = EventType::query()->where('slug', '!=', 'quinceanera')->where('slug', '!=', 'wedding')->first();
        if ($birthday) {
            $event = Events::factory()->draft()->create(['event_type_id' => $birthday->id, 'event_plan_id' => EventPlan::orderBy('id')->value('id'), 'start_date' => now()->addMonths(6)]);
            EventUserRole::create(['event_id' => $event->id, 'user_id' => $o['owner']->id, 'role_id' => Role::where('name', 'owner')->value('id')]);
            $this->actingAs($o['owner'])->getJson("/api/v1/app/event/{$event->id}/assistant/next")->assertOk()
                ->assertJsonPath('data.mode', 'unsupported')->assertJsonPath('data.steps', []);
        }
    });

    it('stays inside the budget of queries: no N+1 whatever the size of the event', function () {
        [$event, $u] = assistantEvent();
        Guest::factory()->count(30)->create(['event_id' => $event->id, 'parent_id' => null]);
        app(EventModules::class)->forget();

        DB::enableQueryLog();
        app(AssistantService::class)->run($event->fresh());
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect($queries)->toBeLessThanOrEqual(15);
    });
});

describe('acting on a step', function () {
    it('lets owner and editor write, and not the viewer', function () {
        [, $u, $url] = assistantEvent();

        $this->actingAs($u['viewer'])->postJson("$url/tasks/budget_cap/done")->assertForbidden()->assertJsonPath('code', 'forbidden');
        $this->actingAs($u['viewer'])->postJson("$url/custom-tasks", ['title' => 'x'])->assertForbidden();
        $this->actingAs($u['stranger'])->postJson("$url/tasks/budget_cap/done")->assertNotFound();
        expect(AssistantTaskState::count())->toBe(0);

        foreach (['owner', 'editor'] as $role) {
            $this->actingAs($u[$role])->postJson("$url/tasks/budget_cap/hide")->assertOk();
            $this->actingAs($u[$role])->postJson("$url/custom-tasks", ['title' => 'Mariachi'])->assertCreated();
        }
    });

    it('marks done, hides, says "not for me" and snoozes, answering the step as it is now', function () {
        [$event, $u, $url] = assistantEvent();
        $owner = $u['owner'];

        $this->actingAs($owner)->postJson("$url/tasks/budget_cap/done")->assertOk()
            ->assertJsonPath('data.key', 'budget_cap')->assertJsonPath('data.state', 'done')->assertJsonPath('data.doneBy', 'user');
        $this->actingAs($owner)->postJson("$url/tasks/guest_list/hide")->assertOk()->assertJsonPath('data.state', 'hidden');
        $this->actingAs($owner)->postJson("$url/tasks/book_ceremony/not-applicable")->assertOk()->assertJsonPath('data.state', 'not_applicable');
        $snoozed = $this->actingAs($owner)->postJson("$url/tasks/book_venue/snooze")->assertOk()->assertJsonPath('data.state', 'upcoming')->json('data.snoozedUntil');

        expect(AssistantTaskState::where('event_id', $event->id)->count())->toBe(4)
            ->and(AssistantTaskState::where('task_key', 'budget_cap')->value('set_by_user_id'))->toBe($owner->id)
            ->and(CarbonImmutable::parse($snoozed)->diffInDays(now(), true))->toBeGreaterThan(12.9)->toBeLessThan(14.1)
            ->and($snoozed)->toEndWith('Z');

        $this->actingAs($owner)->postJson("$url/tasks/book_venue/snooze", ['days' => 30])->assertOk();
        expect(AssistantTaskState::where('task_key', 'book_venue')->count())->toBe(1)
            ->and(AssistantTaskState::where('task_key', 'book_venue')->first()->snoozed_until->diffInDays(now(), true))->toBeGreaterThan(29)->toBeLessThan(31);
    });

    it('is idempotent, replaces the previous action and forgets it on reset', function () {
        [$event, $u, $url] = assistantEvent();
        $owner = $u['owner'];

        $first = $this->actingAs($owner)->postJson("$url/tasks/budget_cap/done")->assertOk()->json('data');
        $again = $this->actingAs($owner)->postJson("$url/tasks/budget_cap/done")->assertOk()->json('data');
        expect($again)->toBe($first)->and(AssistantTaskState::count())->toBe(1);

        $this->actingAs($owner)->postJson("$url/tasks/budget_cap/hide")->assertOk();
        expect(AssistantTaskState::count())->toBe(1)->and(AssistantTaskState::first()->state)->toBe('hidden');

        $this->actingAs($owner)->postJson("$url/tasks/budget_cap/reset")->assertOk()->assertJsonPath('data.state', 'next');
        $this->actingAs($owner)->postJson("$url/tasks/budget_cap/reset")->assertOk();
        expect(AssistantTaskState::count())->toBe(0);
    });

    it('keeps doneBy honest: her mark wins over the data, and reset hands it back to the data', function () {
        [$event, $u, $url] = assistantEvent();
        $this->actingAs($u['owner'])->putJson("/api/v1/app/event/{$event->id}/budget", ['cap' => 5000])->assertOk();

        $budgetTask = fn () => collect($this->actingAs($u['owner'])->getJson("$url/plan")->json('data.phases.0.tasks'))->firstWhere('key', 'budget_cap');
        expect($budgetTask()['doneBy'])->toBe('data');
        $this->actingAs($u['owner'])->postJson("$url/tasks/budget_cap/done")->assertOk()->assertJsonPath('data.doneBy', 'user');
        $this->actingAs($u['owner'])->postJson("$url/tasks/budget_cap/reset")->assertOk()->assertJsonPath('data.doneBy', 'data')->assertJsonPath('data.state', 'done');
    });

    it('answers 404 task_not_found for an unknown key and for a step of the other kind of event', function () {
        [, $u, $url] = assistantEvent('wedding');

        foreach (['done', 'snooze', 'hide', 'not-applicable', 'reset'] as $action) {
            $this->actingAs($u['owner'])->postJson("$url/tasks/not_a_task/$action")->assertNotFound()->assertJsonPath('code', 'task_not_found');
            // `court_of_honor` is for quinceañeras only.
            $this->actingAs($u['owner'])->postJson("$url/tasks/court_of_honor/$action")->assertNotFound()->assertJsonPath('code', 'task_not_found');
        }
        expect(AssistantTaskState::count())->toBe(0);

        AssistantTask::where('key', 'budget_cap')->update(['is_active' => false]);
        $this->actingAs($u['owner'])->postJson("$url/tasks/budget_cap/done")->assertNotFound()->assertJsonPath('code', 'task_not_found');
    });

    it('rejects a bad number of days with invalid_days', function () {
        [, $u, $url] = assistantEvent();

        foreach ([0, 91, -3, 'abc', 1.5, [1]] as $days) {
            $this->actingAs($u['owner'])->postJson("$url/tasks/budget_cap/snooze", ['days' => $days])
                ->assertStatus(422)->assertJsonPath('errors.days.0', 'invalid_days');
        }
        foreach ([1, 90, '7'] as $days) {
            $this->actingAs($u['owner'])->postJson("$url/tasks/budget_cap/snooze", ['days' => $days])->assertOk();
        }
    });

    it('does not mix events nor users: what is done in one event is not done in another', function () {
        [, $a, $urlA] = assistantEvent();
        [, $b, $urlB] = assistantEvent();

        $this->actingAs($a['owner'])->postJson("$urlA/tasks/budget_cap/done")->assertOk();

        expect($this->actingAs($b['owner'])->getJson("$urlB/next")->json('data.steps.0.key'))->toBe('budget_cap');
        // The owner of A cannot touch B, not even by naming it.
        $this->actingAs($a['owner'])->postJson("$urlB/tasks/budget_cap/done")->assertNotFound();
        $this->actingAs($a['owner'])->getJson("$urlB/plan")->assertNotFound();
    });
});

describe('the detectors against real data', function () {
    it('sees a vendor booked in a budget category, even after the category is renamed', function () {
        [$event, $u, $url] = assistantEvent();
        $owner = $u['owner'];
        $budget = "/api/v1/app/event/{$event->id}/budget";

        $categories = $this->actingAs($owner)->getJson($budget)->json('data.categories');
        expect(BudgetCategory::where('slug', 'venue')->count())->toBe(1);
        $venue = collect($categories)->firstWhere('name', 'Salón');

        $this->actingAs($owner)->postJson("$budget/items", ['title' => 'Salón Miami', 'categoryId' => $venue['id'], 'estimatedCost' => 3000])->assertCreated();
        $task = fn () => collect($this->actingAs($owner)->getJson("$url/plan")->json('data.phases.0.tasks'))->firstWhere('key', 'book_venue');
        expect($task()['state'])->not->toBe('done');

        $this->actingAs($owner)->postJson("$budget/items", ['title' => 'Salón Miami 2', 'categoryId' => $venue['id'], 'estimatedCost' => 3000, 'vendorName' => 'Hotel Fontainebleau'])->assertCreated();
        expect($task()['state'])->toBe('done')->and($task()['doneBy'])->toBe('data');

        $this->actingAs($owner)->putJson("$budget/categories/{$venue['id']}", ['name' => 'Donde será la fiesta'])->assertOk();
        expect($task()['state'])->toBe('done');
    });

    it('sees phone numbers on the guest list (80 percent of the main guests)', function () {
        [$event, $u, $url] = assistantEvent('quinceanera', ['start_date' => now()->addMonths(6)]);
        $contact = fn () => collect($this->actingAs($u['owner'])->getJson("$url/plan")->json('data.phases.1.tasks'))->firstWhere('key', 'guests_contact');

        Guest::factory()->count(5)->create(['event_id' => $event->id, 'parent_id' => null, 'phone' => '+13055550100']);
        Guest::factory()->count(5)->create(['event_id' => $event->id, 'parent_id' => null, 'phone' => '']);
        expect($contact()['state'])->not->toBe('done');

        Guest::where('event_id', $event->id)->where('phone', '')->limit(2)->update(['phone' => '+13055550101']);
        expect($contact()['state'])->not->toBe('done'); // 7 of 10
        Guest::where('event_id', $event->id)->where('phone', '')->limit(1)->update(['phone' => '+13055550102']);
        expect($contact()['state'])->toBe('done')->and($contact()['doneBy'])->toBe('data');
    });

    it('sees RSVP answers (80 percent of the main guests have answered)', function () {
        [$event, $u, $url] = assistantEvent('quinceanera', ['start_date' => now()->addMonths(1)]);
        $follow = fn () => collect($this->actingAs($u['owner'])->getJson("$url/plan")->json('data.phases.3.tasks'))->firstWhere('key', 'rsvp_follow_up');

        Guest::factory()->count(8)->create(['event_id' => $event->id, 'parent_id' => null, 'rsvp_status' => 'attending']);
        Guest::factory()->count(2)->create(['event_id' => $event->id, 'parent_id' => null, 'rsvp_status' => 'pending']);
        expect($follow()['state'])->toBe('done')->and($follow()['doneBy'])->toBe('data');

        Guest::where('event_id', $event->id)->where('rsvp_status', 'attending')->limit(2)->update(['rsvp_status' => 'pending']);
        expect($follow()['state'])->not->toBe('done')
            ->and($follow()['reason'])->toContain('4 invitados sin responder');
        // Companions (children rows) do not count as main guests.
        Guest::factory()->count(5)->create(['event_id' => $event->id, 'parent_id' => Guest::where('event_id', $event->id)->first()->id, 'rsvp_status' => 'attending']);
        expect($follow()['state'])->not->toBe('done');
    });

    it('does not count guests of other events nor deleted ones', function () {
        [$event, $u, $url] = assistantEvent('quinceanera', ['start_date' => now()->addMonths(1)]);
        [$other] = assistantEvent();
        Guest::factory()->count(10)->create(['event_id' => $other->id, 'parent_id' => null, 'rsvp_status' => 'attending']);
        $gone = Guest::factory()->count(10)->create(['event_id' => $event->id, 'parent_id' => null, 'rsvp_status' => 'attending']);
        $gone->each->delete();

        $follow = collect($this->actingAs($u['owner'])->getJson("$url/plan")->json('data.phases.3.tasks'))->firstWhere('key', 'rsvp_follow_up');
        expect($follow['state'])->not->toBe('done');
    });
});

describe('custom tasks', function () {
    it('creates, lists in the plan, edits, completes and deletes', function () {
        [$event, $u, $url] = assistantEvent();
        $owner = $u['owner'];

        $created = $this->actingAs($owner)->postJson("$url/custom-tasks", ['title' => '  Cotizar mariachi  ', 'notes' => 'Llamar a Pedro', 'due_date' => '2027-03-01'])
            ->assertCreated()->json('data');
        expect($created)->toMatchArray(['title' => 'Cotizar mariachi', 'notes' => 'Llamar a Pedro', 'dueDate' => '2027-03-01', 'doneAt' => null])
            ->and($created['createdAt'])->toEndWith('Z')
            ->and(AssistantCustomTask::first()->created_by_user_id)->toBe($owner->id);

        $custom = $this->actingAs($owner)->getJson("$url/plan")->json('data.custom');
        expect($custom)->toHaveCount(1)->and($custom[0]['id'])->toBe($created['id']);

        $done = $this->actingAs($owner)->patchJson("$url/custom-tasks/{$created['id']}", ['done' => true])->assertOk()->json('data');
        expect($done['doneAt'])->not->toBeNull()->and($done['title'])->toBe('Cotizar mariachi');
        $edited = $this->actingAs($owner)->patchJson("$url/custom-tasks/{$created['id']}", ['title' => 'Mariachi', 'notes' => null, 'due_date' => null])->assertOk()->json('data');
        expect($edited)->toMatchArray(['title' => 'Mariachi', 'notes' => null, 'dueDate' => null])->and($edited['doneAt'])->toBe($done['doneAt']);
        $this->actingAs($owner)->patchJson("$url/custom-tasks/{$created['id']}", ['done' => false])->assertOk()->assertJsonPath('data.doneAt', null);

        $this->actingAs($owner)->deleteJson("$url/custom-tasks/{$created['id']}")->assertOk()->assertJsonPath('data', null);
        expect(AssistantCustomTask::count())->toBe(0);
    });

    it('answers validation errors as codes', function () {
        [, $u, $url] = assistantEvent();
        $owner = $u['owner'];

        $this->actingAs($owner)->postJson("$url/custom-tasks", [])->assertStatus(422)->assertJsonPath('errors.title.0', 'required');
        $this->actingAs($owner)->postJson("$url/custom-tasks", ['title' => '   '])->assertStatus(422)->assertJsonPath('errors.title.0', 'required');
        $this->actingAs($owner)->postJson("$url/custom-tasks", ['title' => ['x']])->assertStatus(422)->assertJsonPath('errors.title.0', 'required');
        $this->actingAs($owner)->postJson("$url/custom-tasks", ['title' => str_repeat('a', 121)])->assertStatus(422)->assertJsonPath('errors.title.0', 'too_long');
        $this->actingAs($owner)->postJson("$url/custom-tasks", ['title' => 'ok', 'notes' => str_repeat('a', 501)])->assertStatus(422)->assertJsonPath('errors.notes.0', 'too_long');
        foreach (['01/03/2027', '2027-13-45', 'soon', 20270301] as $date) {
            $this->actingAs($owner)->postJson("$url/custom-tasks", ['title' => 'ok', 'due_date' => $date])->assertStatus(422)->assertJsonPath('errors.due_date.0', 'invalid_date');
        }
        $this->actingAs($owner)->postJson("$url/custom-tasks", ['title' => str_repeat('a', 120), 'notes' => str_repeat('b', 500)])->assertCreated();

        $id = AssistantCustomTask::first()->id;
        $this->actingAs($owner)->patchJson("$url/custom-tasks/$id", ['title' => ''])->assertStatus(422)->assertJsonPath('errors.title.0', 'required');
        $this->actingAs($owner)->patchJson("$url/custom-tasks/$id", ['due_date' => 'x'])->assertStatus(422)->assertJsonPath('errors.due_date.0', 'invalid_date');
        $this->actingAs($owner)->patchJson("$url/custom-tasks/$id", ['notes' => str_repeat('c', 501)])->assertStatus(422)->assertJsonPath('errors.notes.0', 'too_long');
        $this->actingAs($owner)->patchJson("$url/custom-tasks/$id", [])->assertOk();
    });

    it('keeps plain text: markup is stored and returned as typed, trimmed, never interpreted', function () {
        [, $u, $url] = assistantEvent();

        $data = $this->actingAs($u['owner'])->postJson("$url/custom-tasks", ['title' => '<script>alert(1)</script>', 'notes' => '<b>hola</b> & adiós'])->assertCreated()->json('data');

        expect($data['title'])->toBe('<script>alert(1)</script>')->and($data['notes'])->toBe('<b>hola</b> & adiós');
    });

    it('allows 50 per event and answers 409 custom_task_limit after that', function () {
        [$event, $u, $url] = assistantEvent();
        [$other] = assistantEvent();
        for ($i = 0; $i < 50; $i++) {
            AssistantCustomTask::create(['event_id' => $event->id, 'title' => "t$i"]);
        }
        AssistantCustomTask::create(['event_id' => $other->id, 'title' => 'other event']);

        $this->actingAs($u['owner'])->postJson("$url/custom-tasks", ['title' => 'one more'])->assertStatus(409)->assertJsonPath('code', 'custom_task_limit');
        AssistantCustomTask::where('event_id', $event->id)->first()->delete();
        $this->actingAs($u['owner'])->postJson("$url/custom-tasks", ['title' => 'now it fits'])->assertCreated();
    });

    it('answers 404 custom_task_not_found for a task of another event, never 403', function () {
        [, $a, $urlA] = assistantEvent();
        [$eventB, $b, $urlB] = assistantEvent();
        $theirs = AssistantCustomTask::create(['event_id' => $eventB->id, 'title' => 'Theirs']);

        foreach ([['patchJson', ['title' => 'mine now']], ['deleteJson', []]] as [$method, $body]) {
            $this->actingAs($a['owner'])->{$method}("$urlA/custom-tasks/{$theirs->id}", $body)->assertNotFound()->assertJsonPath('code', 'custom_task_not_found');
        }
        $this->actingAs($a['owner'])->patchJson("$urlA/custom-tasks/999999", ['title' => 'x'])->assertNotFound()->assertJsonPath('code', 'custom_task_not_found');
        expect($theirs->fresh()->title)->toBe('Theirs');
        // And the owner of B sees her own task only in B's plan.
        expect($this->actingAs($b['owner'])->getJson("$urlB/plan")->json('data.custom'))->toHaveCount(1);
        expect($this->actingAs($a['owner'])->getJson("$urlA/plan")->json('data.custom'))->toBe([]);
    });
});

describe('impersonation', function () {
    it('lets support read the assistant but blocks every write', function () {
        $staff = User::factory()->create();
        $staff->assignRole(AdminPermissions::SUPPORT);
        [, $u, $url] = assistantEvent();
        app('auth')->forgetGuards();
        $token = $this->postJson("/api/v1/admin/users/{$u['owner']->id}/impersonate", ['reason' => 'Revisar el plan'], ['Authorization' => 'Bearer ' . $this->adminBearer($staff, true)])->assertCreated()->json('token');
        $headers = ['Authorization' => 'Bearer ' . $token];

        app('auth')->forgetGuards();
        $this->getJson("$url/next", $headers)->assertOk();
        app('auth')->forgetGuards();
        $this->getJson("$url/plan", $headers)->assertOk();
        foreach ([['postJson', "$url/tasks/budget_cap/done"], ['postJson', "$url/tasks/budget_cap/snooze"], ['postJson', "$url/tasks/budget_cap/hide"], ['postJson', "$url/tasks/budget_cap/not-applicable"], ['postJson', "$url/tasks/budget_cap/reset"], ['postJson', "$url/custom-tasks"], ['patchJson', "$url/custom-tasks/1"], ['deleteJson', "$url/custom-tasks/1"]] as [$method, $path]) {
            app('auth')->forgetGuards();
            $this->{$method}($path, [], $headers)->assertForbidden()->assertJsonPath('code', 'impersonation_blocked');
        }
        expect(AssistantTaskState::count())->toBe(0)->and(AssistantCustomTask::count())->toBe(0);
    });
});

describe('catalog and limits', function () {
    it('seeds the 33 tasks once and never overwrites what the admin edited', function () {
        expect(AssistantTask::count())->toBe(33)
            ->and(AssistantTask::where('event_type', 'quinceanera')->count())->toBe(2)
            ->and(AssistantTask::where('event_type', 'wedding')->count())->toBe(0);

        AssistantTask::where('key', 'book_venue')->update(['title_es' => 'Mi salón', 'start_months' => 13.5, 'is_active' => false]);
        AssistantTask::where('key', 'thank_guests')->delete();

        (new AssistantTaskSeeder)->run();
        (new AssistantTaskSeeder)->run();

        $venue = AssistantTask::where('key', 'book_venue')->first();
        expect(AssistantTask::count())->toBe(33)
            ->and($venue->title_es)->toBe('Mi salón')->and($venue->start_months)->toBe(13.5)->and($venue->is_active)->toBeFalse()
            ->and(AssistantTask::where('key', 'thank_guests')->exists())->toBeTrue();
    });

    it('stores the catalog as the contract defines it', function () {
        $venue = AssistantTask::where('key', 'book_venue')->first();

        expect($venue->prerequisites)->toBe(['budget_cap'])->and($venue->detector)->toBe('budget_vendor:venue')->and($venue->module_key)->toBe('budget')
            ->and($venue->start_months)->toBe(12.0)->and($venue->late_months)->toBe(8.0)->and($venue->route)->toBe('/dashboard/budget')
            ->and(AssistantTask::where('key', 'seating_chart')->value('module_key'))->toBe('seating')
            ->and(AssistantTask::whereNull('detector')->count())->toBe(6);
    });

    it('backfills the budget category slug from the catalog name and leaves renamed ones alone', function () {
        [$event, $u] = assistantEvent();
        $this->actingAs($u['owner'])->getJson("/api/v1/app/event/{$event->id}/budget")->assertOk();
        BudgetCategory::query()->update(['slug' => null]);
        BudgetCategory::where('name', 'Salón')->update(['name' => 'Donde será']);
        BudgetCategory::where('name', 'Pastel')->update(['name' => 'Cake']);

        (require database_path('migrations/2027_04_10_000005_backfill_budget_category_slugs.php'))->up();
        (require database_path('migrations/2027_04_10_000005_backfill_budget_category_slugs.php'))->up();

        expect(BudgetCategory::where('name', 'Donde será')->value('slug'))->toBeNull()
            ->and(BudgetCategory::where('name', 'Cake')->value('slug'))->toBe('cake')
            ->and(BudgetCategory::where('name', 'Banquete')->value('slug'))->toBe('catering')
            ->and(BudgetCategory::where('name', 'Misa o ceremonia')->value('slug'))->toBe('ceremony')
            ->and(BudgetCategory::whereNull('slug')->count())->toBe(1);
    });

    it('gives new budgets categories with a slug in either language and kind of event', function () {
        [$event, $u] = assistantEvent('wedding', ['locale' => 'en']);
        $this->actingAs($u['owner'])->getJson("/api/v1/app/event/{$event->id}/budget")->assertOk();

        expect(BudgetCategory::where('name', 'Venue')->value('slug'))->toBe('venue')
            ->and(BudgetCategory::where('name', 'Attire')->value('slug'))->toBe('attire')
            ->and(BudgetCategory::whereNull('slug')->count())->toBe(0);
    });

    it('names its two rate limiters', function () {
        expect(RouteServiceProvider::ROUTE_LIMITS['assistant-read'])->toBe([120, 1])
            ->and(RouteServiceProvider::ROUTE_LIMITS['assistant-write'])->toBe([60, 1]);

        $limiters = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_contains($r->uri(), '/assistant/'))
            ->mapWithKeys(fn ($r) => [$r->methods()[0] . ' ' . $r->uri() => collect($r->gatherMiddleware())->first(fn ($m) => str_starts_with((string) $m, 'throttle:'))]);
        expect($limiters->filter(fn ($m, $k) => str_starts_with($k, 'GET'))->unique()->values()->all())->toBe(['throttle:assistant-read'])
            ->and($limiters->reject(fn ($m, $k) => str_starts_with($k, 'GET'))->unique()->values()->all())->toBe(['throttle:assistant-write']);
    });
});
