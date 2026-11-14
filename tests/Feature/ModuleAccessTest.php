<?php

use App\Http\Services\AppServices\EventModules;
use App\Models\EventModuleOverride;
use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\EventUserRole;
use App\Models\Guest;
use App\Models\Module;
use App\Models\SuggestedMusic;
use App\Models\SweetMemoriesImage;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

function accessEvent(string $planSlug, string $status = 'draft'): array
{
    $event = Events::factory()->create([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', $planSlug)->value('id'),
        'status' => $status,
    ]);
    $owner = User::factory()->create();
    EventUserRole::create(['event_id' => $event->id, 'user_id' => $owner->id, 'role_id' => Role::where('name', 'owner')->value('id')]);

    return [$event, $owner];
}

function overrideModule(Events $event, string $key, string $state): void
{
    EventModuleOverride::create(['event_id' => $event->id, 'module_key' => $key, 'state' => $state, 'reason' => 'test']);
}

/** The request got past the module guard: a real success, or the controller's own "nothing configured yet" 404. */
function assertReached($response): void
{
    $status = $response->status();
    expect($status === 404 ? $response->json('code') : null)->toBeNull();
    expect($status >= 200 && $status < 300 || $status === 404)->toBeTrue("unexpected status {$status}");
}

function assertLocked($response, string $module): void
{
    $response->assertForbidden()->assertJson(['code' => 'module_locked', 'module' => $module]);
}

// module => organizer GET routes under /event/{event}/ that belong to it
dataset('moduleRoutes', [
    'guests' => ['guests', 'roster'],
    'rsvp' => ['rsvp', 'rsvp/stats'],
    'save_the_date' => ['save_the_date', 'save-the-date'],
    'timeline' => ['timeline', 'timeline'],
    'locations' => ['locations', 'location'],
    'dress_code' => ['dress_code', 'dress-code'],
    'budget' => ['budget', 'budget'],
    'padrinos' => ['padrinos', 'padrinos'],
    'menus' => ['menus', 'menus'],
    'seating' => ['seating', 'tables'],
    'seating (assign)' => ['seating', 'seating/unassigned'],
    'suggested_music' => ['suggested_music', 'suggest-music'],
    'suggested_music (config)' => ['suggested_music', 'suggest-music-config'],
    'memories' => ['memories', 'sweet-memories-config'],
    'memories (images)' => ['memories', 'sweet-memories-images'],
    'comments' => ['comments', 'comments'],
    'comments (config)' => ['comments', 'comments-config'],
]);

describe('organizer routes', function () {
    it('answers 403 module_locked when the plan does not include the module', function (string $module, string $path) {
        [$event, $owner] = accessEvent('basic');
        $event->eventPlan->modules()->detach(); // nothing included, whatever the seeder gave the entry plan
        Sanctum::actingAs($owner);

        assertLocked($this->getJson("/api/v1/app/event/{$event->id}/{$path}"), $module);
    })->with('moduleRoutes');

    it('lets the full plan through', function (string $module, string $path) {
        [$event, $owner] = accessEvent('premium');
        Sanctum::actingAs($owner);

        str_ends_with($path, '-config')
            ? assertReached($this->getJson("/api/v1/app/event/{$event->id}/{$path}"))
            : $this->getJson("/api/v1/app/event/{$event->id}/{$path}")->assertSuccessful();
    })->with('moduleRoutes');

    it('keeps the free modules open and the premium ones locked on the real basic plan', function (string $module, string $path, bool $free) {
        [$event, $owner] = accessEvent('basic'); // the seeder's own assignment, nothing detached
        Sanctum::actingAs($owner);

        $response = $this->getJson("/api/v1/app/event/{$event->id}/{$path}");

        $free ? assertReached($response) : assertLocked($response, $module);
    })->with([
        'guests' => ['guests', 'roster', true],
        'rsvp' => ['rsvp', 'rsvp/stats', true],
        'save_the_date' => ['save_the_date', 'save-the-date', true],
        'timeline' => ['timeline', 'timeline', true],
        'locations' => ['locations', 'location', true],
        'dress_code' => ['dress_code', 'dress-code', true],
        'budget' => ['budget', 'budget', true],
        'padrinos' => ['padrinos', 'padrinos', true],
        'menus' => ['menus', 'menus', false],
        'seating' => ['seating', 'tables', false],
        'suggested_music' => ['suggested_music', 'suggest-music', false],
        'memories' => ['memories', 'sweet-memories-config', false],
        'comments' => ['comments', 'comments', false],
    ]);

    it('lets an active exception through on a plan without the module', function (string $module, string $path) {
        [$event, $owner] = accessEvent('basic');
        $event->eventPlan->modules()->detach();
        overrideModule($event, $module, 'active');
        Sanctum::actingAs($owner);

        str_ends_with($path, '-config')
            ? assertReached($this->getJson("/api/v1/app/event/{$event->id}/{$path}"))
            : $this->getJson("/api/v1/app/event/{$event->id}/{$path}")->assertSuccessful();
    })->with('moduleRoutes');

    it('blocks a locked exception on the full plan', function (string $module, string $path) {
        [$event, $owner] = accessEvent('premium');
        overrideModule($event, $module, 'locked');
        Sanctum::actingAs($owner);

        assertLocked($this->getJson("/api/v1/app/event/{$event->id}/{$path}"), $module);
    })->with('moduleRoutes');

    it('blocks a module the admin switched off, even on the full plan', function (string $module, string $path) {
        [$event, $owner] = accessEvent('premium');
        Module::where('key', $module)->update(['is_enabled' => false]);
        Sanctum::actingAs($owner);

        assertLocked($this->getJson("/api/v1/app/event/{$event->id}/{$path}"), $module);
    })->with('moduleRoutes');

    it('blocks writes too, not only reads', function () {
        [$event, $owner] = accessEvent('basic');
        $event->eventPlan->modules()->detach();
        Sanctum::actingAs($owner);

        assertLocked($this->postJson("/api/v1/app/event/{$event->id}/menus", ['title' => 'x']), 'menus');
        assertLocked($this->deleteJson("/api/v1/app/event/{$event->id}/seating/clear-all"), 'seating');
    });

    it('answers 404 to a stranger on the memory rename route, whatever the module state', function (bool $locked) {
        [$event] = accessEvent('premium');
        if ($locked) {
            overrideModule($event, 'memories', 'locked');
        }
        $image = SweetMemoriesImage::factory()->create(['event_id' => $event->id]);
        Sanctum::actingAs(User::factory()->create());

        $this->patchJson("/api/v1/app/sweet-memories-images/{$image->id}", ['name' => 'Foto'])->assertNotFound();
        $this->patchJson('/api/v1/app/sweet-memories-images/999999', ['name' => 'Foto'])->assertNotFound();
    })->with([true, false]);

    it('keeps the 404 for someone who is not a member of the event', function () {
        [$event] = accessEvent('basic');
        $event->eventPlan->modules()->detach();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/v1/app/event/{$event->id}/menus")->assertNotFound();
    });

    it('leaves the core routes alone', function (string $path) {
        [$event, $owner] = accessEvent('basic');
        $event->eventPlan->modules()->detach();
        Sanctum::actingAs($owner);

        $this->getJson("/api/v1/app/event/{$event->id}/{$path}")->assertSuccessful();
    })->with(['', 'permissions', 'onboarding', 'background-music']);

    it('guards the memory rename route that has no event in its address', function () {
        [$event, $owner] = accessEvent('basic');
        $event->eventPlan->modules()->detach();
        $image = SweetMemoriesImage::factory()->create(['event_id' => $event->id]);
        Sanctum::actingAs($owner);

        assertLocked($this->patchJson("/api/v1/app/sweet-memories-images/{$image->id}", ['name' => 'Foto']), 'memories');

        overrideModule($event, 'memories', 'active');
        $this->patchJson("/api/v1/app/sweet-memories-images/{$image->id}", ['name' => 'Foto'])->assertOk();
    });
});

describe('guest-facing routes', function () {
    it('guards the public comments and suggested music with the module', function (string $module, string $path) {
        [$event] = accessEvent('basic', 'published');
        $event->eventPlan->modules()->detach();
        Guest::factory()->create(['event_id' => $event->id, 'code' => 'VAL001']);
        $url = "/api/v1/app/template/event/{$event->id}/{$path}?accessCode=VAL001";

        assertLocked($this->getJson($url), $module);

        overrideModule($event, $module, 'active');
        $this->getJson($url)->assertOk();
    })->with([['comments', 'comments'], ['suggested_music', 'suggest-music']]);

    it('still answers 404 on a wrong access code before saying anything about the module', function () {
        [$event] = accessEvent('basic', 'published');
        $event->eventPlan->modules()->detach();

        $this->getJson("/api/v1/app/template/event/{$event->id}/comments?accessCode=NOPE99")->assertNotFound();
    });

    it('blocks the public RSVP when the module is locked, and lets the invitation itself open', function () {
        [$event] = accessEvent('premium', 'published');
        $event->update(['custom_url_slug' => 'xv-test']);
        Guest::factory()->create(['event_id' => $event->id, 'code' => 'VAL001']);
        overrideModule($event, 'rsvp', 'locked');

        assertLocked($this->postJson('/api/v1/app/invitation/xv-test/VAL001/rsvp', []), 'rsvp');
        $this->getJson('/api/v1/app/invitation/xv-test/VAL001')->assertOk();
    });

    it('lets the public RSVP reach its validation when the module is active', function () {
        [$event] = accessEvent('premium', 'published');
        $event->update(['custom_url_slug' => 'xv-test']);
        Guest::factory()->create(['event_id' => $event->id, 'code' => 'VAL001']);

        $this->postJson('/api/v1/app/invitation/xv-test/VAL001/rsvp', [])->assertUnprocessable();
    });
});

describe('public invitation', function () {
    function publishedInvitation(string $plan = 'premium'): Events
    {
        [$event] = accessEvent($plan, 'published');
        $event->update(['custom_url_slug' => 'xv-test']);
        Guest::factory()->create(['event_id' => $event->id, 'code' => 'VAL001']);

        return $event;
    }

    it('answers the same 404 to a wrong code, unknown or unpublished slug, whatever the rsvp module state', function (string $state) {
        $event = publishedInvitation();
        overrideModule($event, 'rsvp', $state);
        [$draft] = accessEvent('premium', 'draft');
        $draft->update(['custom_url_slug' => 'xv-draft']);

        $this->postJson('/api/v1/app/invitation/xv-test/NOPE99/rsvp', [])->assertNotFound();
        $this->postJson('/api/v1/app/invitation/no-such-slug/VAL001/rsvp', [])->assertNotFound();
        $this->postJson('/api/v1/app/invitation/xv-draft/VAL001/rsvp', [])->assertNotFound();
    })->with(['locked', 'active']);

    it('leaves out the sections of modules that are not active', function (string $module, string $section) {
        $event = publishedInvitation();
        $event->update(['template_config' => ['sections' => [['type' => $section, 'enabled' => true, 'order' => 1]]]]);
        $types = fn () => collect($this->getJson('/api/v1/app/invitation/xv-test/VAL001')->assertOk()->json('data.sections'))->pluck('type');

        expect($types())->toContain('rsvp'); // baseline: the module is in the plan
        overrideModule($event, $module, 'locked');
        expect($types())->not->toContain($section);
    })->with([
        ['rsvp', 'rsvp'],
    ]);

    it('hides every module-owned section when the plan includes none', function () {
        $event = publishedInvitation();
        $before = collect($this->getJson('/api/v1/app/invitation/xv-test/VAL001')->json('data.sections'))->pluck('type')->all();
        $event->eventPlan->modules()->detach();
        $after = collect($this->getJson('/api/v1/app/invitation/xv-test/VAL001')->assertOk()->json('data.sections'))->pluck('type')->all();

        expect($before)->toContain('rsvp')
            ->and($after)->not->toContain('rsvp', 'itinerary', 'locations', 'dress_code', 'padrinos');
    });

    it('serves no Save the date section when the module is locked', function () {
        $event = publishedInvitation();
        $event->forceFill(['invitation_stage' => Events::STAGE_SAVE_THE_DATE])->save();
        $this->getJson('/api/v1/app/invitation/xv-test/VAL001')->assertOk()->assertJsonPath('data.sections.0.type', 'cover');

        overrideModule($event, 'save_the_date', 'locked');
        expect($this->getJson('/api/v1/app/invitation/xv-test/VAL001')->assertOk()->json('data.sections'))->toBe([]);
    });
});

describe('hydration', function () {
    it('nulls the data of modules that are not active', function () {
        [$event, $owner] = accessEvent('premium');
        $owner->update(['active_event' => $event->id]);
        Sanctum::actingAs($owner);
        $event->eventPlan->modules()->detach();

        $data = $this->getJson("/api/v1/app/user/hydrate/{$owner->id}")->assertOk()->json('data');
        $data = $data['menus'] ?? false ? $data : ($this->getJson("/api/v1/app/user/hydrate/{$owner->id}")->json());

        foreach (['menus', 'menuGuests', 'saveTheDate', 'location', 'comments'] as $key) {
            expect($data)->toHaveKey($key)->and($data[$key])->toBeNull();
        }
    });
});

describe('catalog safety net', function () {
    it('warns once per request when the catalog is empty and keeps everything locked', function () {
        [$event, $owner] = accessEvent('premium');
        Module::query()->delete();
        Log::spy();
        Sanctum::actingAs($owner);

        assertLocked($this->getJson("/api/v1/app/event/{$event->id}/budget"), 'budget');

        Log::shouldHaveReceived('warning')->once();
    });

    it('repairs a bare database with the migration without touching the admin changes', function () {
        $migration = require base_path('database/migrations/2026_12_95_000001_seed_module_catalog_if_missing.php');
        [$event] = accessEvent('premium');
        Events::withoutGlobalScopes()->whereKey($event->id)->update(['event_plan_id' => null]);
        DB::table('module_plan')->delete();
        Module::where('key', 'budget')->update(['is_enabled' => false]); // an admin decision

        // modules exist but plans have no rows
        $migration->up();

        $basic = EventPlan::where('slug', 'basic')->first();
        $premium = EventPlan::where('slug', 'premium')->first();
        expect($basic->modules()->pluck('key')->all())->toContain('guests', 'rsvp')->not->toContain('menus')
            ->and($premium->modules()->pluck('key')->all())->toContain('menus', 'guests')
            ->and(Module::where('key', 'budget')->value('is_enabled'))->toBeFalsy()
            ->and($event->fresh()->event_plan_id)->toBe($basic->id);

        // and it is idempotent
        $count = DB::table('module_plan')->count();
        $migration->up();
        expect(DB::table('module_plan')->count())->toBe($count);
    });

    it('seeds a database with no catalog at all', function () {
        $migration = require base_path('database/migrations/2026_12_95_000001_seed_module_catalog_if_missing.php');
        DB::table('module_plan')->delete();
        Module::query()->delete();

        $migration->up();

        expect(Module::count())->toBeGreaterThan(5)
            ->and(EventPlan::where('slug', 'basic')->first()->modules()->pluck('key')->all())->toContain('guests');
    });
});

describe('guest voting on suggested music', function () {
    it('does not accept a song of another event', function () {
        [$event] = accessEvent('premium', 'published');
        [$other] = accessEvent('premium', 'published');
        Guest::factory()->create(['event_id' => $event->id, 'code' => 'VAL001']);
        $song = SuggestedMusic::create(['event_id' => $other->id, 'title' => 'Song', 'artist' => 'Band', 'album' => 'LP', 'platform' => 'spotify', 'platformId' => 'x1', 'thumbnailUrl' => 'u', 'previewUrl' => 'u', 'suggested_by_entity' => 'guest', 'suggested_by_id' => 1]);

        $this->getJson("/api/v1/app/template/event/{$event->id}/suggest-music/{$song->id}/vote?accessCode=VAL001")->assertNotFound();
        $this->postJson("/api/v1/app/template/event/{$event->id}/suggest-music/{$song->id}/vote", ['accessCode' => 'VAL001', 'direction' => 'up'])->assertNotFound();
    });
});

describe('exceptions are read per event', function () {
    it('asks for the rows of one event only', function () {
        [$event] = accessEvent('premium');
        [$other] = accessEvent('premium');
        overrideModule($other, 'budget', 'locked');

        DB::enableQueryLog();
        app(EventModules::class)->isActive($event, 'budget');
        $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => str_contains($q, 'event_module_overrides'));
        DB::disableQueryLog();

        expect($queries)->toHaveCount(1)->and($queries->first())->toContain('"event_id"');
    });
});
