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

function suggestedMusicMigration(): object
{
    return require database_path('migrations/2027_07_05_000001_add_suggested_music_to_templates.php');
}

/** Puts the Classic quinceañera back in the state it had before the section existed. */
function stripSuggestedMusic(): InvitationTemplate
{
    $template = InvitationTemplate::where('slug', 'classic-quinceanera')->firstOrFail();
    $version = DB::table('invitation_template_versions')->find($template->current_version_id);
    $sections = array_values(array_filter(json_decode($version->sections, true), fn ($s) => $s['type'] !== 'suggested_music'));

    DB::table('invitation_template_versions')->where('id', $template->current_version_id)->update(['sections' => json_encode($sections)]);
    DB::table('invitation_templates')->where('id', $template->id)->update(['default_sections' => json_encode($sections)]);

    return $template->fresh();
}

function sectionsOf(int $versionId): array
{
    $sections = json_decode(DB::table('invitation_template_versions')->find($versionId)->sections, true);
    usort($sections, fn ($a, $b) => $a['order'] <=> $b['order']);

    return $sections;
}

it('is already satisfied on a fresh install: the seeder brings the section, switched on', function () {
    $template = InvitationTemplate::where('slug', 'classic-quinceanera')->firstOrFail();
    $before = $template->current_version_id;

    suggestedMusicMigration()->up();

    $music = collect(sectionsOf($before))->firstWhere('type', 'suggested_music');
    expect($template->fresh()->current_version_id)->toBe($before)
        ->and($music['enabled'])->toBeTrue()
        ->and(array_column(sectionsOf($before), 'type'))->toBe(['cover', 'itinerary', 'locations', 'dress_code', 'padrinos', 'gallery', 'rsvp', 'tables', 'suggested_music', 'comments', 'music', 'closing']);
});

it('starts new events with the section on, through the seeder', function () {
    $wedding = InvitationTemplate::where('slug', 'classic-wedding')->firstOrFail();

    foreach ([InvitationTemplate::where('slug', 'classic-quinceanera')->first(), $wedding] as $template) {
        $music = collect(sectionsOf($template->current_version_id))->firstWhere('type', 'suggested_music');
        expect($music['enabled'])->toBeTrue()->and($music['title']['es'])->toBe('Tu música');
    }
});

it('publishes the next version with the section on, between tables and comments', function () {
    $template = stripSuggestedMusic();
    $old = $template->current_version_id;

    suggestedMusicMigration()->up();

    $template = $template->fresh();
    $new = DB::table('invitation_template_versions')->find($template->current_version_id);
    $sections = collect(sectionsOf($new->id));
    $types = $sections->pluck('type')->all();

    expect($template->current_version_id)->not->toBe($old)
        ->and($new->status)->toBe('published')
        ->and(DB::table('invitation_template_versions')->find($old)->status)->toBe('archived')
        ->and($sections->firstWhere('type', 'suggested_music')['enabled'])->toBeTrue()
        ->and($sections->pluck('order')->all())->toBe(range(1, $sections->count()))
        ->and(array_search('suggested_music', $types))->toBeGreaterThan(array_search('tables', $types))
        ->and(array_search('suggested_music', $types))->toBeLessThan(array_search('comments', $types));
});

it('moves the fixed events to it with the section off, keeping their own choices, and leaves drafts following', function () {
    $template = stripSuggestedMusic();
    $type = EventType::where('slug', 'quinceanera')->value('id');
    $plan = EventPlan::where('slug', 'premium')->value('id');

    $fixed = Events::factory()->create([
        'event_type_id' => $type, 'event_plan_id' => $plan, 'status' => 'published', 'custom_url_slug' => 'xv-publicado',
        'template_id' => $template->id, 'template_version_id' => $template->current_version_id,
        'template_config' => ['sections' => [['type' => 'gallery', 'enabled' => false]]],
    ]);
    Guest::factory()->create(['event_id' => $fixed->id, 'parent_id' => null, 'code' => 'FIX123']);
    $draft = Events::factory()->draft()->create(['event_type_id' => $type, 'event_plan_id' => $plan, 'template_id' => $template->id, 'template_version_id' => null]);

    suggestedMusicMigration()->up();

    $fixed = $fixed->fresh();
    $config = collect($fixed->template_config['sections'])->keyBy('type');

    expect($fixed->template_version_id)->toBe($template->fresh()->current_version_id)
        ->and($config['suggested_music']['enabled'])->toBeFalse()
        ->and($config['gallery']['enabled'])->toBeFalse()
        ->and($draft->fresh()->template_version_id)->toBeNull();

    $types = collect($this->getJson('/api/v1/app/invitation/xv-publicado/FIX123')->assertOk()->json('data.sections'))->pluck('type')->all();
    expect($types)->not->toContain('suggested_music');
});

it('changes nothing the second time', function () {
    stripSuggestedMusic();
    suggestedMusicMigration()->up();
    $versions = DB::table('invitation_template_versions')->count();
    $current = InvitationTemplate::where('slug', 'classic-quinceanera')->value('current_version_id');

    suggestedMusicMigration()->up();

    expect(DB::table('invitation_template_versions')->count())->toBe($versions)
        ->and(InvitationTemplate::where('slug', 'classic-quinceanera')->value('current_version_id'))->toBe($current);
});

it('leaves alone the versions that a template package manages', function () {
    $template = stripSuggestedMusic();
    $package = DB::table('template_packages')->insertGetId([
        'slug' => 'pkg-test', 'semver' => '1.0.0', 'tier' => 'free', 'engine_min' => 1, 'manifest' => '{}', 'tokens' => '{}',
        'editable' => '{}', 'ast' => '{}', 'files' => '[]', 'report' => '{}', 'content_hash' => str_repeat('a', 64),
        'zip_sha256' => str_repeat('b', 64), 'size_bytes' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('invitation_template_versions')->where('id', $template->current_version_id)->update(['package_id' => $package]);
    $current = $template->current_version_id;

    suggestedMusicMigration()->up();

    expect($template->fresh()->current_version_id)->toBe($current);
});
