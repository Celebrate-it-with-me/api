<?php

use App\Models\EventModuleOverride;
use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\EventUserRole;
use App\Models\Guest;
use App\Models\Module;
use App\Models\SweetMemoriesImage;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        expect($this->getJson("/api/v1/app/event/{$event->id}/{$path}")->status())->not->toBe(403);
    })->with('moduleRoutes');

    it('lets an active exception through on a plan without the module', function (string $module, string $path) {
        [$event, $owner] = accessEvent('basic');
        $event->eventPlan->modules()->detach();
        overrideModule($event, $module, 'active');
        Sanctum::actingAs($owner);

        expect($this->getJson("/api/v1/app/event/{$event->id}/{$path}")->status())->not->toBe(403);
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

        expect($this->getJson("/api/v1/app/event/{$event->id}/{$path}")->status())->not->toBe(403);
    })->with(['', 'permissions', 'suggestions', 'onboarding', 'background-music']);

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
