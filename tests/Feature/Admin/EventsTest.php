<?php

use App\Models\EventActivity;
use App\Models\EventPlan;
use App\Models\EventPurchase;
use App\Models\Events;
use App\Models\EventType;
use App\Models\EventUserRole;
use App\Models\Guest;
use App\Models\User;
use App\Support\AdminPermissions;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    User::role(AdminPermissions::STAFF_ROLES)->get()->each(fn (User $u) => $u->syncRoles([]));
});

function evStaff(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function evMake(User $owner, array $attrs = [], string $type = 'quinceanera', string $plan = 'basic'): Events
{
    $event = Events::factory()->draft()->create($attrs + [
        'event_type_id' => EventType::where('slug', $type)->value('id'),
        'event_plan_id' => EventPlan::where('slug', $plan)->value('id'),
    ]);
    EventUserRole::create(['event_id' => $event->id, 'user_id' => $owner->id, 'role_id' => Role::where('name', 'owner')->value('id')]);

    return $event;
}

function evOwner(string $name = 'Marisol Perez', ?string $email = null): User
{
    return User::factory()->create(['name' => $name, 'email' => $email ?? strtolower(str_replace(' ', '.', $name)) . '@example.com']);
}

describe('permissions', function () {
    it('answers 401 to anonymous, 403 to organizers, 200 to support and superadmin', function () {
        $event = evMake(evOwner());

        $urls = ['/api/v1/admin/events', "/api/v1/admin/events/{$event->id}"];
        foreach ($urls as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
        $organizer = evOwner('Some Organizer');
        foreach ($urls as $url) {
            $this->actingAs($organizer)->getJson($url)->assertForbidden();
            $this->actingAs(evStaff('support'))->getJson($url)->assertOk();
            $this->actingAs(evStaff('superadmin'))->getJson($url)->assertOk();
        }
    });
});

describe('list', function () {
    it('returns the row contract', function () {
        $owner = evOwner('Marisol Perez');
        $event = evMake($owner, ['event_name' => 'XV de Valentina', 'custom_url_slug' => 'xv-valentina']);
        Guest::factory()->count(3)->create(['event_id' => $event->id]);
        EventPurchase::create(['event_id' => $event->id, 'user_id' => $owner->id, 'event_plan_id' => $event->event_plan_id, 'gateway' => 'fake',
            'list_price_cents' => 1000, 'amount_cents' => 1000, 'status' => 'pending']);

        $res = $this->actingAs(evStaff('support'))->getJson('/api/v1/admin/events')->assertOk();

        $res->assertJsonPath('meta.total', 1)->assertJsonPath('meta.page', 1)->assertJsonPath('meta.lastPage', 1)
            ->assertJsonPath('data.0.id', $event->id)
            ->assertJsonPath('data.0.name', 'XV de Valentina')
            ->assertJsonPath('data.0.slug', 'xv-valentina')
            ->assertJsonPath('data.0.type.slug', 'quinceanera')
            ->assertJsonPath('data.0.plan.slug', 'basic')
            ->assertJsonPath('data.0.status', 'draft')
            ->assertJsonPath('data.0.stage', 'invitation')
            ->assertJsonPath('data.0.owner.email', 'marisol.perez@example.com')
            ->assertJsonPath('data.0.guestCount', 3)
            ->assertJsonPath('data.0.lastPurchaseStatus', 'pending');
        expect($res->json('data.0'))->toHaveKeys(['startDate', 'createdAt']);
    });

    it('searches by event name, slug, owner name and owner email', function () {
        $a = evMake(evOwner('Ana Lopez', 'ana@example.com'), ['event_name' => 'Boda Gris', 'custom_url_slug' => 'boda-gris']);
        $b = evMake(evOwner('Luis Mora', 'luis@familia.org'), ['event_name' => 'XV Sol', 'custom_url_slug' => 'sol-quince']);
        $staff = evStaff('support');
        $ids = fn (string $q) => collect($this->actingAs($staff)->getJson('/api/v1/admin/events?search=' . urlencode($q))->assertOk()->json('data'))->pluck('id')->all();

        expect($ids('boda'))->toBe([$a->id])
            ->and($ids('SOL-QUINCE'))->toBe([$b->id])
            ->and($ids('lopez'))->toBe([$a->id])
            ->and($ids('familia.org'))->toBe([$b->id])
            ->and($ids('%'))->toBe([])
            ->and($ids('nothing here'))->toBe([]);
    });

    it('filters by status, stage, plan, type, dates and pending payment', function () {
        $o = evOwner();
        $draft = evMake($o, ['start_date' => '2027-03-10 18:00:00']);
        $published = evMake($o, ['status' => 'published', 'published_at' => now(), 'invitation_stage' => 'save_the_date', 'start_date' => '2027-06-10 18:00:00'], 'wedding', 'premium');
        $archived = evMake($o, ['status' => 'archived', 'start_date' => '2027-09-10 18:00:00']);
        EventPurchase::create(['event_id' => $archived->id, 'user_id' => $o->id, 'event_plan_id' => $archived->event_plan_id, 'gateway' => 'fake',
            'list_price_cents' => 1000, 'amount_cents' => 1000, 'status' => 'failed']);
        EventPurchase::create(['event_id' => $draft->id, 'user_id' => $o->id, 'event_plan_id' => $draft->event_plan_id, 'gateway' => 'fake',
            'list_price_cents' => 1000, 'amount_cents' => 1000, 'status' => 'paid']);
        $staff = evStaff('support');
        $ids = fn (string $qs) => collect($this->actingAs($staff)->getJson("/api/v1/admin/events?$qs")->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

        expect($ids('status=published'))->toBe([$published->id])
            ->and($ids('status=archived'))->toBe([$archived->id])
            ->and($ids('stage=save_the_date'))->toBe([$published->id])
            ->and($ids('plan=premium'))->toBe([$published->id])
            ->and($ids('type=wedding'))->toBe([$published->id])
            ->and($ids('from=2027-05-01&to=2027-07-01'))->toBe([$published->id])
            ->and($ids('from=2027-06-10&to=2027-06-10'))->toBe([$published->id])
            ->and($ids('paymentPending=1'))->toBe([$archived->id])
            ->and($ids(''))->toBe(collect([$draft->id, $published->id, $archived->id])->sort()->values()->all());
    });

    it('validates filters', function () {
        $this->actingAs(evStaff('support'))->getJson('/api/v1/admin/events?status=bogus&perPage=51&from=nope')
            ->assertUnprocessable()->assertJsonValidationErrors(['status', 'perPage', 'from']);
    });

    it('paginates and sorts', function () {
        $o = evOwner();
        $e1 = evMake($o, ['start_date' => '2027-05-01 10:00:00']);
        $e2 = evMake($o, ['start_date' => '2027-03-01 10:00:00']);
        $e3 = evMake($o, ['start_date' => '2027-04-01 10:00:00']);
        $staff = evStaff('support');

        $page1 = $this->actingAs($staff)->getJson('/api/v1/admin/events?perPage=2')->assertOk();
        $page2 = $this->actingAs($staff)->getJson('/api/v1/admin/events?perPage=2&page=2')->assertOk();
        $byDate = $this->actingAs($staff)->getJson('/api/v1/admin/events?sort=startDate')->assertOk();

        expect($page1->json('meta'))->toBe(['page' => 1, 'perPage' => 2, 'total' => 3, 'lastPage' => 2])
            ->and(collect($page1->json('data'))->pluck('id')->all())->toBe([$e3->id, $e2->id])
            ->and(collect($page2->json('data'))->pluck('id')->all())->toBe([$e1->id])
            ->and(collect($byDate->json('data'))->pluck('id')->all())->toBe([$e2->id, $e3->id, $e1->id]);
    });

    it('leaves out internal accounts unless asked', function () {
        $real = evMake(evOwner('Real Mom', 'mom@example.com'));
        $demo = evMake(User::factory()->create(['email' => 'demo@cwm.test']));
        $staffOwned = evMake(evStaff('superadmin'));
        $viewer = evStaff('support');
        $ids = fn (string $qs) => collect($this->actingAs($viewer)->getJson("/api/v1/admin/events?$qs")->json('data'))->pluck('id')->sort()->values()->all();

        expect($ids(''))->toBe([$real->id])
            ->and($ids('internal=0'))->toBe([$real->id])
            ->and($ids('internal=1'))->toBe(collect([$real->id, $demo->id, $staffOwned->id])->sort()->values()->all());
    });

    it('does not run more queries with 25 events than with 1', function () {
        $staff = evStaff('support');
        $o = evOwner();
        $first = evMake($o);
        Guest::factory()->create(['event_id' => $first->id]);
        $this->actingAs($staff);

        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/admin/events?perPage=50')->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $this->getJson('/api/v1/admin/events')->assertOk(); // warm up (permission cache)
        $one = $count();

        foreach (range(1, 24) as $i) {
            $e = evMake(evOwner("Owner $i", "owner$i@example.com"), ['custom_url_slug' => "evt-$i"]);
            Guest::factory()->count(2)->create(['event_id' => $e->id]);
            EventPurchase::create(['event_id' => $e->id, 'user_id' => $e->owner_user_id, 'event_plan_id' => $e->event_plan_id, 'gateway' => 'fake',
                'list_price_cents' => 1, 'amount_cents' => 1, 'status' => 'paid']);
        }
        $many = $count();

        expect($this->getJson('/api/v1/admin/events?perPage=50')->json('meta.total'))->toBe(25)->and($many)->toBe($one);
    });
});

describe('summary', function () {
    it('returns the whole summary in one response', function () {
        config(['app.frontend_app.url' => 'https://app.example.com/']);
        $owner = evOwner('Marisol Perez');
        $event = evMake($owner, ['event_name' => 'XV de Valentina', 'custom_url_slug' => 'xv-valentina', 'status' => 'published', 'published_at' => now()]);
        $editor = evOwner('Carlos Editor');
        EventUserRole::create(['event_id' => $event->id, 'user_id' => $editor->id, 'role_id' => Role::where('name', 'editor')->value('id')]);
        Guest::factory()->count(2)->create(['event_id' => $event->id, 'rsvp_status' => 'attending']);
        Guest::factory()->create(['event_id' => $event->id, 'rsvp_status' => 'not-attending']);
        Guest::factory()->count(4)->create(['event_id' => $event->id, 'rsvp_status' => 'pending']);
        EventPurchase::create(['event_id' => $event->id, 'user_id' => $owner->id, 'event_plan_id' => $event->event_plan_id, 'gateway' => 'stripe',
            'gateway_session_id' => 'cs_secret', 'gateway_payment_id' => 'pi_secret', 'receipt_url' => 'https://r', 'list_price_cents' => 4900, 'amount_cents' => 4900, 'status' => 'failed']);
        EventPurchase::create(['event_id' => $event->id, 'user_id' => $owner->id, 'event_plan_id' => $event->event_plan_id, 'gateway' => 'stripe',
            'list_price_cents' => 4900, 'amount_cents' => 4900, 'status' => 'pending']);
        foreach (range(1, 12) as $i) {
            EventActivity::logActivity($event->id, 'guest_created', $owner, null, ['name' => "G$i"]);
        }
        $budgetId = DB::table('event_budgets')->insertGetId(['event_id' => $event->id, 'budget_cap' => 5000, 'created_at' => now(), 'updated_at' => now()]);
        $catId = DB::table('budget_categories')->insertGetId(['name' => 'Salon', 'event_budget_id' => $budgetId, 'created_at' => now(), 'updated_at' => now()]);
        $itemId = DB::table('budget_items')->insertGetId(['event_budget_id' => $budgetId, 'category_id' => $catId, 'title' => 'Venue', 'estimated_cost' => 900, 'agreed_cost' => 1000, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('budget_items')->insert(['event_budget_id' => $budgetId, 'category_id' => $catId, 'title' => 'Cake', 'estimated_cost' => 200, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('budget_payments')->insert(['budget_item_id' => $itemId, 'amount' => 300, 'paid_on' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);

        $res = $this->actingAs(evStaff('superadmin'))->getJson("/api/v1/admin/events/{$event->id}")->assertOk();

        $res->assertJsonPath('data.event.name', 'XV de Valentina')
            ->assertJsonPath('data.event.slugLocked', true)
            ->assertJsonPath('data.event.type.slug', 'quinceanera')
            ->assertJsonPath('data.owner.email', 'marisol.perez@example.com')
            ->assertJsonCount(1, 'data.collaborators')
            ->assertJsonPath('data.collaborators.0.role', 'editor')
            ->assertJsonPath('data.plan.slug', 'basic')
            ->assertJsonPath('data.payment.lastPurchase.status', 'pending')
            ->assertJsonCount(2, 'data.payment.purchases')
            ->assertJsonPath('data.publicLink.isLive', true)
            ->assertJsonPath('data.publicLink.url', 'https://app.example.com/i/xv-valentina')
            ->assertJsonPath('data.publicLink.pattern', 'https://app.example.com/i/xv-valentina/{code}')
            ->assertJsonPath('data.counts.guests', 7)
            ->assertJsonPath('data.counts.attending', 2)
            ->assertJsonPath('data.counts.notAttending', 1)
            ->assertJsonPath('data.counts.pending', 4)
            ->assertJsonPath('data.counts.expenses.items', 2)
            ->assertJsonPath('data.counts.expenses.total', 1200)
            ->assertJsonPath('data.counts.expenses.paid', 300)
            ->assertJsonCount(10, 'data.recentActivity')
            ->assertJsonPath('data.recentActivity.0.actorName', 'Marisol Perez');
        expect($res->json('data.modules'))->not->toBeEmpty()->and($res->json('data.modules.0'))->toHaveKeys(['key', 'section', 'state']);
        expect($res->getContent())->not->toContain('cs_secret')->not->toContain('pi_secret')->not->toContain('receipt');
    });

    it('has null expenses without a budget and finds archived and soft-deleted events', function () {
        $event = evMake(evOwner(), ['status' => 'archived']);
        $staff = evStaff('support');
        $this->actingAs($staff)->getJson("/api/v1/admin/events/{$event->id}")->assertOk()
            ->assertJsonPath('data.counts.expenses', null)->assertJsonPath('data.event.status', 'archived')->assertJsonPath('data.event.deletedAt', null);

        $event->delete();
        $this->actingAs($staff)->getJson("/api/v1/admin/events/{$event->id}")->assertOk()->assertJsonPath('data.event.deletedAt', fn ($v) => $v !== null);
        $this->actingAs($staff)->getJson('/api/v1/admin/events/999999')->assertNotFound();
    });

    it('does not run more queries with 25 collaborators, guests and activities than with none', function () {
        $staff = evStaff('support');
        $small = evMake(evOwner());
        EventActivity::logActivity($small->id, 'guest_created', User::find($small->owner_user_id), null, ['name' => 'G']);
        $big = evMake(evOwner('Big Owner', 'big@example.com'));
        foreach (range(1, 25) as $i) {
            EventUserRole::create(['event_id' => $big->id, 'user_id' => evOwner("Col $i", "col$i@example.com")->id, 'role_id' => Role::where('name', 'viewer')->value('id')]);
            Guest::factory()->create(['event_id' => $big->id]);
            EventActivity::logActivity($big->id, 'guest_created', User::find($big->owner_user_id), null, ['name' => "G$i"]);
        }
        $this->actingAs($staff)->getJson("/api/v1/admin/events/{$small->id}")->assertOk();
        $queries = function (Events $e) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson("/api/v1/admin/events/{$e->id}")->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        expect($queries($big))->toBe($queries($small));
    });
});

it('does not show payments or amounts to support, only to who manages purchases', function () {
    $owner = evOwner('Marisol Perez');
    $event = evMake($owner, ['event_name' => 'XV de Valentina']);
    EventPurchase::create(['event_id' => $event->id, 'user_id' => $owner->id, 'event_plan_id' => $event->event_plan_id, 'gateway' => 'manual',
        'list_price_cents' => 9900, 'amount_cents' => 7900, 'status' => 'paid']);

    $support = $this->actingAs(evStaff('support'))->getJson("/api/v1/admin/events/{$event->id}")->assertOk();
    $super = $this->actingAs(evStaff('superadmin'))->getJson("/api/v1/admin/events/{$event->id}")->assertOk();

    expect($support->json('data.payment'))->toBeNull()
        ->and($support->json('data.plan.priceCents'))->toBeNull()
        ->and($support->getContent())->not->toContain('7900')
        ->and($super->json('data.payment.lastPurchase.amountCents'))->toBe(7900);
});
