<?php

use App\Http\Services\AppServices\EventModules;
use App\Models\EventModuleOverride;
use App\Models\EventPlan;
use App\Models\Module;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ModuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

require_once __DIR__ . '/CollaboratorsTest.php';

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

function onPlan($event, string $slug): void
{
    $event->update(['event_plan_id' => EventPlan::where('slug', $slug)->value('id')]);
}

describe('the collaborators module in the catalog', function () {
    it('is created on, in the organization section at order 60, and only in the premium plan', function () {
        $module = Module::where('key', 'collaborators')->firstOrFail();

        expect($module->section)->toBe('organization')
            ->and($module->sort_order)->toBe(60)
            ->and($module->is_enabled)->toBeTrue()
            ->and($module->plans()->pluck('slug')->all())->toContain('premium')->not->toContain('basic');
    });

    it('is created by the data migration when missing, and the migration is idempotent and keeps admin changes', function () {
        $migration = require database_path('migrations/2027_03_22_000001_seed_collaborators_module.php');

        Module::where('key', 'collaborators')->first()->plans()->detach();
        Module::where('key', 'collaborators')->delete();

        $migration->up();
        $module = Module::where('key', 'collaborators')->firstOrFail();
        expect($module->plans()->pluck('slug')->all())->toContain('premium')->not->toContain('basic');

        // The admin changes it: switch off, other order, plan taken away. Running again changes nothing.
        $module->update(['is_enabled' => false, 'sort_order' => 5]);
        $module->plans()->detach();
        $migration->up();
        $migration->up();

        expect(Module::where('key', 'collaborators')->count())->toBe(1)
            ->and($module->refresh())->is_enabled->toBeFalse()->sort_order->toBe(5)
            ->and($module->plans()->count())->toBe(0);
    });

    it('is not pushed to a plan when it already existed', function () {
        $count = DB::table('module_plan')->count();
        $this->seed(ModuleSeeder::class);

        expect(DB::table('module_plan')->count())->toBe($count);
    });
});

describe('collaborators on the basic plan', function () {
    it('lists modules[] with collaborators locked, and active on premium', function () {
        [$event, $m] = eventWithMembers();

        onPlan($event, 'basic');
        $basic = $this->actingAs($m['owner'])->getJson("/api/v1/app/event/{$event->id}")->assertOk()->json('data.modules');
        onPlan($event, 'premium');
        $premium = $this->actingAs($m['owner'])->getJson("/api/v1/app/event/{$event->id}")->assertOk()->json('data.modules');

        expect(collect($basic)->firstWhere('key', 'collaborators'))->toBe(['key' => 'collaborators', 'section' => 'organization', 'order' => 60, 'state' => 'locked'])
            ->and(collect($premium)->firstWhere('key', 'collaborators'))->toBe(['key' => 'collaborators', 'section' => 'organization', 'order' => 60, 'state' => 'active']);
    });

    it('blocks inviting, changing a role and resending with 403 module_locked', function () {
        Mail::fake();
        [$event, $m] = eventWithMembers();
        $invite = pendingInvite($event, $m['owner']);
        onPlan($event, 'basic');

        $this->actingAs($m['owner'])->postJson(collabApi($event), ['email' => 'nueva2@x.test', 'role' => 'viewer'])
            ->assertForbidden()->assertJsonPath('code', 'module_locked');
        $this->actingAs($m['owner'])->patchJson(collabApi($event, "/{$m['viewer']->id}"), ['role' => 'editor'])
            ->assertForbidden()->assertJsonPath('code', 'module_locked');
        $this->actingAs($m['owner'])->postJson(collabApi($event, "/invite/{$invite->id}/resend"))
            ->assertForbidden()->assertJsonPath('code', 'module_locked');

        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    });

    it('still lets the owner list, remove a collaborator and cancel an invitation', function () {
        [$event, $m] = eventWithMembers();
        $invite = pendingInvite($event, $m['owner']);
        onPlan($event, 'basic');

        $this->actingAs($m['owner'])->getJson(collabApi($event))->assertOk();
        $this->actingAs($m['owner'])->deleteJson(collabApi($event, "/invite/{$invite->id}"))->assertSuccessful();
        $this->actingAs($m['owner'])->deleteJson(collabApi($event, "/{$m['viewer']->id}"))->assertSuccessful();
    });

    it('gives a stranger the same 404 as before, not the lock', function () {
        [$event, $m] = eventWithMembers();
        onPlan($event, 'basic');

        $this->actingAs($m['stranger'])->postJson(collabApi($event), ['email' => 'x@x.test', 'role' => 'viewer'])->assertNotFound();
    });

    it('honors a staff exception per event in both directions', function () {
        Mail::fake();
        [$event, $m] = eventWithMembers();
        onPlan($event, 'basic');

        EventModuleOverride::create(['event_id' => $event->id, 'module_key' => 'collaborators', 'state' => 'active', 'reason' => 'gift']);
        $this->actingAs($m['owner'])->postJson(collabApi($event), ['email' => 'regalo@x.test', 'role' => 'viewer'])->assertSuccessful();

        EventModuleOverride::query()->delete();
        onPlan($event, 'premium');
        EventModuleOverride::create(['event_id' => $event->id, 'module_key' => 'collaborators', 'state' => 'locked', 'reason' => 'hand-locked']);
        $this->actingAs($m['owner'])->postJson(collabApi($event), ['email' => 'otra@x.test', 'role' => 'viewer'])
            ->assertForbidden()->assertJsonPath('code', 'module_locked');
    });

    it('is switched off by the admin: locked for everyone', function () {
        [$event, $m] = eventWithMembers();
        Module::where('key', 'collaborators')->update(['is_enabled' => false]);

        $this->actingAs($m['owner'])->postJson(collabApi($event), ['email' => 'x@x.test', 'role' => 'viewer'])
            ->assertForbidden()->assertJsonPath('code', 'module_locked');
        expect(app(EventModules::class)->stateOf($event, 'collaborators'))->toBeNull();
    });
});

describe('collaborators on the premium plan', function () {
    it('allows inviting, changing a role, resending, listing, removing and cancelling', function () {
        Mail::fake();
        [$event, $m] = eventWithMembers();
        $invite = pendingInvite($event, $m['owner']);

        $this->actingAs($m['owner'])->postJson(collabApi($event), ['email' => 'nueva2@x.test', 'role' => 'viewer'])->assertSuccessful();
        $this->actingAs($m['owner'])->patchJson(collabApi($event, "/{$m['viewer']->id}"), ['role' => 'editor'])->assertSuccessful();
        $this->actingAs($m['owner'])->postJson(collabApi($event, "/invite/{$invite->id}/resend"))->assertSuccessful();
        $this->actingAs($m['owner'])->getJson(collabApi($event))->assertOk();
        $this->actingAs($m['owner'])->deleteJson(collabApi($event, "/invite/{$invite->id}"))->assertSuccessful();
        $this->actingAs($m['owner'])->deleteJson(collabApi($event, "/{$m['editor']->id}"))->assertSuccessful();
    });
});

describe('the watermark', function () {
    it('stays on in the admin template preview', function () {
        $source = file_get_contents(app_path('Http/Services/Admin/Templates/TemplatePreview.php'));

        expect($source)->toContain("'branding' => ['watermark' => true]");
    });
});
