<?php

use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\EventUserRole;
use App\Models\InvitationTemplate;
use App\Models\Seating\Table;
use App\Models\TemplatePackage;
use App\Models\User;
use App\Support\AdminPermissions;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\Unit\TemplatePackage\PackageBuilder;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    User::role(AdminPermissions::STAFF_ROLES)->get()->each(fn (User $u) => $u->syncRoles([]));
    Storage::fake('template-packages');
    Storage::fake('local');
    Storage::fake('event-media');
    config(['invitation.engine' => 1]);
});

afterEach(fn () => PackageBuilder::cleanup());

function mcBuilder(string $slug, string $blocks = ''): PackageBuilder
{
    $b = PackageBuilder::good()->json('manifest.json', fn (array $m) => [...$m, 'slug' => $slug, 'tier' => 'free', 'name' => ['es' => 'Fiesta ' . $slug, 'en' => 'Feast ' . $slug]]);

    return $blocks === '' ? $b : $b->with('layout.html', "<div class=\"pkg-frame\">\n  <q-sections></q-sections>\n  {$blocks}\n</div>\n");
}

function mcInstall(PackageBuilder $b): array
{
    $admin = User::factory()->create();
    $admin->assignRole('superadmin');
    test()->actingAsReauthed($admin);
    $stage = test()->post('/api/v1/admin/templates/packages/validate', ['package' => UploadedFile::fake()->createWithContent('p.zip', (string) file_get_contents($b->zip()))], ['Accept' => 'application/json'])->assertOk()->json('data');
    $package = test()->postJson('/api/v1/admin/templates/packages', ['stage_token' => $stage['stageToken'], 'reason' => 'Instalar el paquete'])->assertCreated()->json('data');
    test()->postJson("/api/v1/admin/templates/packages/{$package['id']}/activate", [
        'reason' => 'Activar tras revisar',
        'checklist' => ['accessibility' => true, 'contrast' => true, 'long_names' => true, 'no_photo' => true, 'sections_reordered' => true, 'performance' => true],
    ])->assertOk();
    app('auth')->forgetGuards();

    return $package;
}

/** Premium-plan quinceañera with an owner; its template is the package one (slug) or the native default. */
function mcEvent(?string $packageSlug = null): array
{
    $event = Events::factory()->draft()->create([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', 'premium')->value('id'),
        'template_id' => $packageSlug ? InvitationTemplate::where('slug', "{$packageSlug}-quinceanera")->value('id') : null,
    ]);
    $owner = User::factory()->create();
    EventUserRole::create(['event_id' => $event->id, 'user_id' => $owner->id, 'role_id' => Role::where('name', 'owner')->value('id')]);

    return [$event, $owner, "/api/v1/app/event/{$event->id}"];
}

function mcComments(): array
{
    return ['title' => 't', 'subTitle' => 's', 'backgroundColor' => '#fff', 'commentsTitle' => 'c', 'buttonColor' => '#000', 'buttonText' => 'Enviar', 'maxComments' => 5];
}

it('stores the supported modules of a package at install', function () {
    $bare = mcInstall(mcBuilder('desnuda'));
    $full = mcInstall(mcBuilder('completa', '<q-comments></q-comments><q-tables></q-tables>'));
    $half = mcInstall(mcBuilder('media', '<q-tables></q-tables>'));

    expect(TemplatePackage::find($bare['id'])->supported_modules)->toBe([])
        ->and(TemplatePackage::find($full['id'])->supported_modules)->toBe(['comments', 'seating'])
        ->and(TemplatePackage::find($half['id'])->supported_modules)->toBe(['seating']);
});

it('lists missingModules in the template selector', function () {
    mcInstall(mcBuilder('desnuda'));
    mcInstall(mcBuilder('completa', '<q-comments></q-comments><q-tables></q-tables>'));
    mcInstall(mcBuilder('media', '<q-tables></q-tables>'));
    [, $owner, $api] = mcEvent();

    $list = collect($this->actingAs($owner)->getJson("$api/invitation/templates")->assertOk()->json('data'));

    expect($list->firstWhere('slug', 'desnuda-quinceanera')['missingModules'])->toBe(['comments', 'seating', 'suggested_music'])
        ->and($list->firstWhere('slug', 'completa-quinceanera')['missingModules'])->toBe(['suggested_music'])
        ->and($list->firstWhere('slug', 'media-quinceanera')['missingModules'])->toBe(['comments', 'suggested_music'])
        ->and($list->every(fn ($t) => is_array($t['missingModules'])))->toBeTrue()
        ->and($list->filter(fn ($t) => str_starts_with($t['slug'], 'classic') || str_starts_with($t['slug'], 'botanical'))->every(fn ($t) => $t['missingModules'] === []))->toBeTrue();
});

it('answers 409 template_missing_module when the package lacks the comments block', function () {
    mcInstall(mcBuilder('desnuda'));
    [$event, $owner, $api] = mcEvent('desnuda');

    $this->actingAs($owner)->postJson("$api/comments-config", mcComments())
        ->assertStatus(409)
        ->assertExactJson([
            'code' => 'template_missing_module',
            'message' => 'La plantilla de tu invitación no incluye este módulo. Cambia de plantilla para activarlo.',
            'module' => 'comments',
            'template' => ['slug' => 'desnuda-quinceanera', 'name' => 'Fiesta desnuda'],
        ]);
    expect($event->eventConfigComment()->exists())->toBeFalse();
});

it('allows the module when the package carries the block', function () {
    mcInstall(mcBuilder('completa', '<q-comments></q-comments><q-tables></q-tables>'));
    [, $owner, $api] = mcEvent('completa');

    $this->actingAs($owner)->postJson("$api/comments-config", mcComments())->assertSuccessful();
    $this->actingAs($owner)->postJson("$api/tables", ['name' => 'Mesa 1', 'capacity' => 2, 'type' => 'family', 'priority' => 1])->assertCreated();
});

it('never blocks a native template', function () {
    [, $owner, $api] = mcEvent();

    $this->actingAs($owner)->postJson("$api/comments-config", mcComments())->assertSuccessful();
    $this->actingAs($owner)->postJson("$api/tables", ['name' => 'Mesa 1', 'capacity' => 2, 'type' => 'family', 'priority' => 1])->assertCreated();
});

it('blocks the first table of a package without the tables block, but not later ones nor changes', function () {
    mcInstall(mcBuilder('desnuda'));
    [$event, $owner, $api] = mcEvent('desnuda');
    $data = ['name' => 'Mesa 1', 'capacity' => 2, 'type' => 'family', 'priority' => 1];

    $this->actingAs($owner)->postJson("$api/tables", $data)->assertStatus(409)->assertJsonPath('module', 'seating')->assertJsonPath('code', 'template_missing_module');

    // A module already in use (tables exist) is never blocked, nor edited or removed.
    $id = Table::query()->create(['event_id' => $event->id, 'name' => 'Previa', 'capacity' => 2, 'type' => 'family', 'priority' => 1])->id;
    $this->actingAs($owner)->postJson("$api/tables", $data)->assertCreated();
    $this->actingAs($owner)->putJson("$api/tables/$id", $data)->assertOk();
    $this->actingAs($owner)->deleteJson("$api/tables/$id")->assertSuccessful();
});

it('does not block the comments update of an event that already has the module', function () {
    mcInstall(mcBuilder('desnuda'));
    [$event, $owner, $api] = mcEvent('desnuda');
    $config = $event->eventConfigComment()->create(['title' => 't', 'sub_title' => 's', 'background_color' => '#fff', 'comments_title' => 'c', 'button_color' => '#000', 'button_text' => 'x', 'max_comments' => 5]);

    $this->actingAs($owner)->putJson("$api/comments-config/{$config->id}", mcComments())->assertSuccessful();
});

it('backfills supported_modules idempotently for installed packages', function () {
    $full = mcInstall(mcBuilder('completa', '<q-comments></q-comments>'));
    $bare = mcInstall(mcBuilder('desnuda'));
    DB::table('template_packages')->update(['supported_modules' => null]);
    $eventsBefore = DB::table('events')->count();
    $migration = require base_path('database/migrations/2027_06_25_000001_add_supported_modules_to_template_packages.php');

    $migration->up();
    $once = DB::table('template_packages')->orderBy('id')->pluck('supported_modules', 'id')->all();
    $migration->up();

    expect(json_decode($once[$full['id']], true))->toBe(['comments'])
        ->and(json_decode($once[$bare['id']], true))->toBe([])
        ->and(DB::table('template_packages')->orderBy('id')->pluck('supported_modules', 'id')->all())->toBe($once)
        ->and(DB::table('events')->count())->toBe($eventsBefore);
});

function mcMusicConfig(): array
{
    return ['title' => 't', 'subTitle' => 's', 'mainColor' => '#000', 'secondaryColor' => '#fff', 'usePreview' => true, 'useVoteSystem' => true, 'searchLimit' => 5];
}

it('reports suggested_music in missingModules and in the stored support of a package', function () {
    $bare = mcInstall(mcBuilder('sinmusica', '<q-comments></q-comments><q-tables></q-tables>'));
    $full = mcInstall(mcBuilder('conmusica', '<q-suggested-music></q-suggested-music>'));
    [, $owner, $api] = mcEvent();

    $list = collect($this->actingAs($owner)->getJson("$api/invitation/templates")->assertOk()->json('data'));

    expect(TemplatePackage::find($bare['id'])->supported_modules)->toBe(['comments', 'seating'])
        ->and(TemplatePackage::find($full['id'])->supported_modules)->toBe(['suggested_music'])
        ->and($list->firstWhere('slug', 'sinmusica-quinceanera')['missingModules'])->toBe(['suggested_music'])
        ->and($list->firstWhere('slug', 'conmusica-quinceanera')['missingModules'])->toBe(['comments', 'seating']);
});

it('answers 409 template_missing_module on the first suggested music configuration of a package without the block', function () {
    mcInstall(mcBuilder('sinmusica'));
    [$event, $owner, $api] = mcEvent('sinmusica');

    $this->actingAs($owner)->postJson("$api/suggest-music-config", mcMusicConfig())
        ->assertStatus(409)
        ->assertExactJson([
            'code' => 'template_missing_module',
            'message' => 'La plantilla de tu invitación no incluye este módulo. Cambia de plantilla para activarlo.',
            'module' => 'suggested_music',
            'template' => ['slug' => 'sinmusica-quinceanera', 'name' => 'Fiesta sinmusica'],
        ]);
    expect($event->suggestedMusicConfig()->exists())->toBeFalse();
});

it('allows suggested music when the package carries the block, and never blocks a native template', function () {
    mcInstall(mcBuilder('conmusica', '<q-suggested-music></q-suggested-music>'));
    [, $owner, $api] = mcEvent('conmusica');
    [, $nativeOwner, $nativeApi] = mcEvent();

    $this->actingAs($owner)->postJson("$api/suggest-music-config", mcMusicConfig())->assertSuccessful();
    $this->actingAs($nativeOwner)->postJson("$nativeApi/suggest-music-config", mcMusicConfig())->assertSuccessful();
});
