<?php

use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\Guest;
use App\Models\InvitationTemplate;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

function commentsTablesMigration(): object
{
    return require database_path('migrations/2027_06_20_000001_add_comments_and_tables_to_templates.php');
}

/** Puts the Classic quinceañera back in the state it had before the two sections existed. */
function stripNewSections(): InvitationTemplate
{
    $template = InvitationTemplate::where('slug', 'classic-quinceanera')->firstOrFail();
    $strip = fn (array $sections) => array_values(array_filter($sections, fn ($s) => ! in_array($s['type'], ['comments', 'tables'], true)));
    $version = $template->currentVersion ?? DB::table('invitation_template_versions')->find($template->current_version_id);
    $sections = $strip(json_decode(json_encode($version->sections), true));

    DB::table('invitation_template_versions')->where('id', $template->current_version_id)->update(['sections' => json_encode($sections)]);
    DB::table('invitation_templates')->where('id', $template->id)->update(['default_sections' => json_encode($sections)]);

    return $template->fresh();
}

function typesOf(int $versionId): array
{
    $sections = json_decode(DB::table('invitation_template_versions')->find($versionId)->sections, true);
    usort($sections, fn ($a, $b) => $a['order'] <=> $b['order']);

    return array_column($sections, 'type');
}

it('is already satisfied on a fresh install: the seeder brings both sections', function () {
    $template = InvitationTemplate::where('slug', 'classic-quinceanera')->firstOrFail();
    $before = $template->current_version_id;

    commentsTablesMigration()->up();

    expect($template->fresh()->current_version_id)->toBe($before)
        ->and(typesOf($before))->toContain('tables', 'comments');
});

it('publishes the next version with both sections before closing, switched on', function () {
    $template = stripNewSections();
    $old = $template->current_version_id;
    expect(typesOf($old))->not->toContain('comments');

    commentsTablesMigration()->up();

    $template = $template->fresh();
    $new = DB::table('invitation_template_versions')->find($template->current_version_id);
    $sections = collect(json_decode($new->sections, true))->sortBy('order')->values();

    expect($template->current_version_id)->not->toBe($old)
        ->and($new->status)->toBe('published')
        ->and($new->version)->toBe(2)
        ->and(DB::table('invitation_template_versions')->find($old)->status)->toBe('archived')
        ->and($sections->pluck('type')->all())->toContain('tables', 'comments')
        ->and($sections->pluck('order')->all())->toBe(range(1, $sections->count()))
        ->and($sections->firstWhere('type', 'comments')['enabled'])->toBeTrue()
        ->and($sections->firstWhere('type', 'tables')['enabled'])->toBeTrue();

    $types = $sections->pluck('type')->all();
    expect(array_search('tables', $types))->toBeGreaterThan(array_search('rsvp', $types))
        ->and(array_search('comments', $types))->toBeLessThan(array_search('closing', $types));
});

it('moves the fixed events to it with both sections off, and leaves drafts following the template', function () {
    $template = stripNewSections();
    $type = EventType::where('slug', 'quinceanera')->value('id');
    $plan = EventPlan::where('slug', 'premium')->value('id');

    $fixed = Events::factory()->create([
        'event_type_id' => $type, 'event_plan_id' => $plan, 'status' => 'published', 'custom_url_slug' => 'xv-publicado',
        'template_id' => $template->id, 'template_version_id' => $template->current_version_id,
        'template_config' => ['sections' => [['type' => 'gallery', 'enabled' => false]]],
    ]);
    Guest::factory()->create(['event_id' => $fixed->id, 'parent_id' => null, 'code' => 'FIX123']);
    $draft = Events::factory()->draft()->create(['event_type_id' => $type, 'event_plan_id' => $plan, 'template_id' => $template->id, 'template_version_id' => null]);

    commentsTablesMigration()->up();

    $template = $template->fresh();
    $fixed = $fixed->fresh();
    $config = collect($fixed->template_config['sections'])->keyBy('type');

    expect($fixed->template_version_id)->toBe($template->current_version_id)
        ->and($config['comments']['enabled'])->toBeFalse()
        ->and($config['tables']['enabled'])->toBeFalse()
        ->and($config['gallery']['enabled'])->toBeFalse() // what she already had is kept
        ->and($draft->fresh()->template_version_id)->toBeNull();

    // The invitation already sent does not change; the organizer can still turn the sections on.
    $types = collect($this->getJson('/api/v1/app/invitation/xv-publicado/FIX123')->assertOk()->json('data.sections'))->pluck('type')->all();
    expect($types)->not->toContain('comments');
});

it('changes nothing the second time', function () {
    stripNewSections();
    commentsTablesMigration()->up();
    $versions = DB::table('invitation_template_versions')->count();
    $current = InvitationTemplate::where('slug', 'classic-quinceanera')->value('current_version_id');

    commentsTablesMigration()->up();

    expect(DB::table('invitation_template_versions')->count())->toBe($versions)
        ->and(InvitationTemplate::where('slug', 'classic-quinceanera')->value('current_version_id'))->toBe($current);
});

it('leaves alone the versions that a template package manages', function () {
    $template = stripNewSections();
    $package = DB::table('template_packages')->insertGetId([
        'slug' => 'pkg-test', 'semver' => '1.0.0', 'tier' => 'free', 'engine_min' => 1, 'manifest' => '{}', 'tokens' => '{}',
        'editable' => '{}', 'ast' => '{}', 'files' => '[]', 'report' => '{}', 'content_hash' => str_repeat('a', 64),
        'zip_sha256' => str_repeat('b', 64), 'size_bytes' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('invitation_template_versions')->where('id', $template->current_version_id)->update(['package_id' => $package]);
    $current = $template->current_version_id;

    commentsTablesMigration()->up();

    expect($template->fresh()->current_version_id)->toBe($current);
});
