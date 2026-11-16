<?php

use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\EventUserRole;
use App\Models\Guest;
use App\Models\Seating\Table;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/*
 * The responses of the hot endpoints (user/hydrate, events, tables) are a contract with the SPA. This test pins them to a
 * stored snapshot built from a fixed fixture, so that the query work (eager loading, aggregates, caches) cannot change a
 * key or a value. To refresh the snapshot on purpose: UPDATE_SNAPSHOTS=1 php artisan test --filter=QueryContractTest
 */

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

/** Ids of the seeded plans and types depend on the sequence: replace them with their slug. */
function contractNormalize(mixed $data, array $types, array $plans): mixed
{
    if (! is_array($data)) {
        return $data;
    }

    foreach ($data as $key => $value) {
        if ($key === 'eventType' && is_int($value)) {
            $data[$key] = $types[$value] ?? $value;
        } elseif (($key === 'eventTypes' || $key === 'eventPlans') && is_array($value)) {
            $map = $key === 'eventTypes' ? $types : $plans;
            $data[$key] = array_map(fn ($row) => array_merge($row, ['id' => $map[$row['id']] ?? $row['id']]), $value);
        } else {
            $data[$key] = contractNormalize($value, $types, $plans);
        }
    }

    // Seeded plan rows carry the seeding time.
    unset($data['created_at_seeded']);

    return $data;
}

function contractFixture(): array
{
    Carbon::setTestNow(Carbon::parse('2027-03-01 12:00:00', 'UTC'));
    $stamp = ['created_at' => '2027-03-01 12:00:00', 'updated_at' => '2027-03-01 12:00:00'];
    $type = EventType::where('slug', 'quinceanera')->value('id');
    $premium = EventPlan::where('slug', 'premium')->value('id');
    $basic = EventPlan::where('slug', 'basic')->value('id');
    $roles = Role::pluck('id', 'name');

    $mkUser = fn (int $id, string $name) => User::factory()->create(['id' => $id, 'name' => $name, 'email' => "contract{$id}@cwm.test", 'created_at' => '2027-01-02 10:00:00', 'updated_at' => '2027-01-02 10:00:00']);
    $owner = $mkUser(9101, 'Contract Owner');
    $other = $mkUser(9102, 'Contract Other');
    $stranger = $mkUser(9103, 'Contract Stranger');

    $mkEvent = fn (int $id, string $slug, int $plan, array $extra = []) => Events::factory()->create(array_merge([
        'id' => $id, 'event_name' => "Contract $slug", 'event_description' => "Description $slug", 'event_type_id' => $type, 'event_plan_id' => $plan,
        'start_date' => '2027-06-01 18:00:00', 'end_date' => '2027-06-02 01:00:00', 'status' => 'draft', 'custom_url_slug' => $slug,
        'published_at' => null, 'created_at' => '2027-02-01 09:00:00', 'updated_at' => '2027-02-01 09:00:00',
    ], $extra));

    $full = $mkEvent(9201, 'contract-full', $premium, ['status' => 'published', 'published_at' => '2027-02-10 09:00:00']);
    $bare = $mkEvent(9202, 'contract-bare', $basic);
    $shared = $mkEvent(9203, 'contract-shared', $premium);
    $hidden = $mkEvent(9204, 'contract-hidden', $premium);

    $role = fn (Events $e, User $u, string $r) => EventUserRole::create(['event_id' => $e->id, 'user_id' => $u->id, 'role_id' => $roles[$r]]);
    $role($full, $owner, 'owner');
    $role($bare, $owner, 'owner');
    $role($shared, $other, 'owner');
    $role($shared, $owner, 'editor');
    $role($full, $other, 'viewer');
    $role($hidden, $stranger, 'owner');

    // Guests: main guests with and without companions, every answer.
    $gid = 9300;
    $mk = function (Events $e, string $name, ?string $status, ?int $parent = null) use (&$gid, $stamp) {
        return Guest::forceCreate(['id' => ++$gid, 'event_id' => $e->id, 'parent_id' => $parent, 'name' => $name, 'email' => "g{$gid}@example.com", 'phone' => null,
            'code' => $parent ? null : sprintf('CTR%04d', $gid), 'rsvp_status' => $status, 'is_vip' => false, 'tags' => [], 'notes' => null, 'gender' => 'other', 'locale' => 'es']);
    };
    $statuses = ['attending', 'not-attending', 'pending', 'attending', 'pending', 'attending'];
    $mains = [];
    foreach ($statuses as $i => $status) {
        $mains[] = $mk($full, "Guest $i", $status);
    }
    $mk($full, 'Companion A', 'attending', $mains[0]->id);
    $mk($full, 'Companion B', 'pending', $mains[0]->id);
    $mk($full, 'Companion C', 'attending', $mains[3]->id);
    $mk($bare, 'Bare 1', 'pending');
    $mk($bare, 'Bare 2', 'attending');
    $mk($shared, 'Shared 1', 'not-attending');
    $deleted = $mk($full, 'Deleted guest', 'attending');
    $deleted->delete();

    // The full event has everything the setup progress looks at.
    $i = fn (string $table, array $row) => DB::table($table)->insert(array_merge($row, $stamp));
    foreach ([$full, $shared] as $e) {
        $i('save_the_dates', ['event_id' => $e->id, 'message' => 'Save the date for '.$e->id]);
        $i('sweet_memories_config', ['event_id' => $e->id]);
        $i('suggested_music_configs', ['event_id' => $e->id]);
    }
    $i('event_config_comment', ['event_id' => $full->id, 'title' => 'T', 'sub_title' => 'S', 'comments_title' => 'C', 'max_comments' => 5, 'button_text' => 'B', 'button_color' => '#000', 'background_color' => '#fff']);
    $i('menus', ['id' => 9401, 'event_id' => $full->id, 'title' => 'Menu uno']);
    $i('menus', ['id' => 9402, 'event_id' => $full->id, 'title' => 'Menu dos']);
    $i('menu_items', ['menu_id' => 9401, 'name' => 'Item', 'type' => 'main']);
    $i('menus', ['id' => 9403, 'event_id' => $shared->id, 'title' => 'Menu vacio']);
    $i('sweet_memories_images', ['event_id' => $full->id, 'image_original_name' => 'a.jpg', 'image_path' => 'a.jpg', 'image_name' => 'a', 'image_size' => '10']);
    $i('suggested_music', ['event_id' => $full->id, 'album' => 'a', 'suggested_by_id' => $owner->id, 'suggested_by_entity' => 'user', 'thumbnailUrl' => 'u', 'platformId' => 'p', 'artist' => 'a', 'title' => 't']);
    $i('event_locations', ['id' => 9501, 'event_id' => $full->id, 'name' => 'Hall', 'country' => 'US', 'city' => 'Miami', 'address' => '1 Main St']);
    $budget = DB::table('event_budgets')->insertGetId(array_merge(['event_id' => $full->id], $stamp));
    DB::table('budget_items')->insert(array_merge(['event_budget_id' => $budget, 'title' => 'Cake'], $stamp));
    DB::table('event_budgets')->insert(array_merge(['event_id' => $shared->id], $stamp));

    // Seating: two tables, one full.
    $tid = 9600;
    foreach ([['Mesa A', 2, 5], ['Mesa B', 6, 1], ['Mesa C', 0, 3]] as [$name, $cap, $prio]) {
        $t = Table::forceCreate(['id' => ++$tid, 'event_id' => $full->id, 'name' => $name, 'capacity' => $cap, 'type' => 'family', 'priority' => $prio]);
        DB::table('tables')->where('id', $t->id)->update($stamp);
    }
    foreach ([[9601, $mains[0]->id], [9601, $mains[1]->id], [9602, $mains[2]->id], [9602, $mains[3]->id]] as $n => [$table, $guest]) {
        DB::table('table_assignments')->insert(array_merge(['table_id' => $table, 'guest_id' => $guest, 'seat_number' => 'Seat '.($n + 1), 'assigned_at' => '2027-03-01 12:00:00', 'assigned_by' => $owner->id], $stamp));
    }

    $owner->forceFill(['last_active_event_id' => $full->id])->save();

    return [$owner, $other, $full, $bare, $shared];
}

function contractSnapshot(string $name, array $actual): void
{
    $path = base_path("tests/Fixtures/perf-contract/{$name}.json");
    $json = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";

    if (getenv('UPDATE_SNAPSHOTS') || ! file_exists($path)) {
        file_put_contents($path, $json);
    }

    expect($json)->toBe(file_get_contents($path));
}

function contractNormalized($response): array
{
    $types = EventType::pluck('slug', 'id')->all();
    $plans = EventPlan::pluck('slug', 'id')->all();
    $data = contractNormalize($response->json(), $types, $plans);

    // Seeded plans carry their seeding time.
    foreach (['eventPlans'] as $key) {
        if (isset($data[$key])) {
            $data[$key] = array_map(fn ($p) => array_diff_key($p, ['created_at' => 1, 'updated_at' => 1]), $data[$key]);
        }
    }

    return $data;
}

it('keeps the user/hydrate response unchanged', function () {
    [$owner] = contractFixture();

    $response = $this->actingAs($owner)->getJson("/api/v1/app/user/hydrate/{$owner->id}")->assertOk();

    contractSnapshot('hydrate', contractNormalized($response));
});

it('keeps the user/hydrate response unchanged for a collaborator on a shared event', function () {
    [$owner, , , , $shared] = contractFixture();
    $owner->forceFill(['last_active_event_id' => $shared->id])->save();

    $response = $this->actingAs($owner)->getJson("/api/v1/app/user/hydrate/{$owner->id}")->assertOk();

    contractSnapshot('hydrate-shared', contractNormalized($response));
});

it('keeps the events list response unchanged', function () {
    [$owner] = contractFixture();

    $response = $this->actingAs($owner)->getJson('/api/v1/app/events')->assertOk();

    contractSnapshot('events', contractNormalized($response));
});

it('keeps the event filter response unchanged', function () {
    [$owner] = contractFixture();

    $response = $this->actingAs($owner)->getJson('/api/v1/app/event/filters?query=contract')->assertOk();

    contractSnapshot('events-filter', contractNormalized($response));
});

it('keeps the tables response unchanged', function () {
    [$owner, , $full] = contractFixture();

    $response = $this->actingAs($owner)->getJson("/api/v1/app/event/{$full->id}/tables")->assertOk();

    contractSnapshot('tables', contractNormalized($response));
});

it('does not hand out the events or tables of someone else', function () {
    [, , $full, , $shared] = contractFixture();
    $stranger = User::find(9103);

    $this->actingAs($stranger)->getJson("/api/v1/app/event/{$full->id}/tables")->assertNotFound();

    $list = $this->actingAs($stranger)->getJson('/api/v1/app/events')->assertOk()->json('data.events');
    expect(collect($list)->pluck('id')->all())->toBe([9204]);

    $owner = User::find(9101);
    $this->actingAs($stranger)->getJson("/api/v1/app/user/hydrate/{$owner->id}")->assertForbidden();
});
