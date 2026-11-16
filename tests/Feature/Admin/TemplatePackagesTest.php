<?php

use App\Http\Services\Admin\Templates\Packages\MissingPackagesUrl;
use App\Http\Services\Admin\Templates\Packages\PackageFiles;
use App\Http\Services\Admin\Templates\Packages\PackageStage;
use App\Http\Services\AppServices\EventLifecycleService;
use App\Http\Services\AppServices\EventTemplateConfigValidator;
use App\Http\Services\AppServices\PackagePayload;
use App\Http\Services\AppServices\TemplateCatalog;
use App\Models\AdminAuditLog;
use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\Guest;
use App\Models\InvitationTemplate;
use App\Models\InvitationTemplateVersion;
use App\Models\TemplatePackage;
use App\Models\User;
use App\Support\AdminPermissions;
use App\Support\ProductionSafetyCheck;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Unit\TemplatePackage\PackageBuilder;

uses(RefreshDatabase::class);

const PKG = '/api/v1/admin/templates/packages';

/** The six items of the quality checklist that activating a package confirms. */
const PKG_CHECKLIST = ['accessibility' => true, 'contrast' => true, 'long_names' => true, 'no_photo' => true, 'sections_reordered' => true, 'performance' => true];

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    User::role(AdminPermissions::STAFF_ROLES)->get()->each(fn (User $u) => $u->syncRoles([]));
    Storage::fake('template-packages');
    Storage::fake('local');
    config(['invitation.engine' => 1]);
});

afterEach(fn () => PackageBuilder::cleanup());

function pkgStaff(string $role = 'superadmin'): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/** Signs in a superadmin with a fresh re-authentication (the install, activate and withdraw routes need it). */
function pkgAdmin(): User
{
    $admin = pkgStaff();
    test()->actingAsReauthed($admin);

    return $admin;
}

function pkgUpload(PackageBuilder $builder, string $name = 'botanical.zip')
{
    return test()->post(PKG . '/validate', ['package' => UploadedFile::fake()->createWithContent($name, (string) file_get_contents($builder->zip()))], ['Accept' => 'application/json']);
}

/** Uploads and installs a package through the endpoints. @return array<string, mixed> the installed Package */
function pkgInstall(?PackageBuilder $builder = null): array
{
    $stage = pkgUpload($builder ?? PackageBuilder::good())->assertOk()->json('data');
    expect($stage['valid'])->toBeTrue(json_encode($stage['report']['errors']));

    return test()->postJson(PKG, ['stage_token' => $stage['stageToken'], 'reason' => 'Instalar el paquete'])->assertCreated()->json('data');
}

function pkgActivate(int $id): array
{
    return test()->postJson(PKG . "/{$id}/activate", ['reason' => 'Activar tras revisar', 'checklist' => PKG_CHECKLIST])->assertOk()->json('data');
}

/** A good package with another version (and the content hash changed by a CSS tweak). */
function pkgVersion(string $version, ?callable $edit = null): PackageBuilder
{
    $b = PackageBuilder::good()->json('manifest.json', fn ($m) => [...$m, 'version' => $version]);
    $b->files['theme.css'] .= "\n.pkg-v" . str_replace('.', '', $version) . '{color:red}';

    return $edit ? $edit($b) : $b;
}

/** A published event of the given type pinned to whatever version its template has now. */
function pkgEvent(string $slug, string $type = 'wedding', array $extra = []): Events
{
    $event = Events::factory()->draft()->create($extra + [
        'event_type_id' => EventType::where('slug', $type)->value('id'),
        'event_plan_id' => EventPlan::where('slug', 'premium')->value('id'),
        'event_name' => 'Boda de Camila y Andrés',
        'custom_url_slug' => $slug,
        'start_date' => now()->addMonths(3),
        'template_id' => InvitationTemplate::where('slug', "botanical-{$type}")->value('id'),
    ]);
    Guest::factory()->create(['event_id' => $event->id, 'code' => 'PKG123', 'parent_id' => null]);

    return $event;
}

function pkgPublish(Events $event): Events
{
    return app(EventLifecycleService::class)->publish($event, null);
}

describe('access', function () {
    it('answers 401 without a session and 403 to non-staff and to support', function () {
        $this->getJson(PKG)->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson(PKG)->assertForbidden();
        $this->actingAs(pkgStaff(AdminPermissions::SUPPORT))->getJson(PKG)->assertForbidden();
        $this->actingAs(pkgStaff(AdminPermissions::SUPPORT))->post(PKG . '/validate', [], ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAs(pkgStaff())->getJson(PKG)->assertOk();
    });

    it('asks for a recent re-authentication on install, activate, deactivate and withdraw (403 reauth_required)', function () {
        $this->actingAs(pkgStaff());
        foreach ([['postJson', PKG], ['postJson', PKG . '/1/activate'], ['postJson', PKG . '/1/deactivate'], ['deleteJson', PKG . '/1']] as [$method, $url]) {
            $this->{$method}($url, ['reason' => 'Una razón válida'])->assertForbidden()->assertJson(['code' => 'reauth_required']);
        }
    });

    it('also asks for it to publish and archive a version (D-3)', function () {
        $version = InvitationTemplate::where('slug', 'classic-quinceanera')->firstOrFail()->current_version_id;
        $this->actingAs(pkgStaff());
        $this->postJson("/api/v1/admin/templates/versions/{$version}/publish", ['reason' => 'Una razón válida'])->assertForbidden()->assertJson(['code' => 'reauth_required']);
        $this->postJson("/api/v1/admin/templates/versions/{$version}/archive", ['reason' => 'Una razón válida'])->assertForbidden()->assertJson(['code' => 'reauth_required']);
    });

    it('is out of reach of an impersonation token (admin routes refuse it)', function () {
        $staff = pkgStaff();
        $owner = User::factory()->create();
        app('auth')->forgetGuards();
        $imp = $this->postJson("/api/v1/admin/users/{$owner->id}/impersonate", ['reason' => 'Auditar bloqueos'], ['Authorization' => 'Bearer ' . $this->adminBearer($staff, true)])
            ->assertCreated()->json('token');

        foreach ([['GET', PKG], ['POST', PKG . '/validate'], ['POST', '/api/v1/admin/templates/preview']] as [$method, $url]) {
            app('auth')->forgetGuards();
            $this->json($method, $url, [], ['Authorization' => 'Bearer ' . $imp])->assertForbidden();
        }
    });
});

describe('validate (upload)', function () {
    it('answers 200 with the report and a stage token for a valid package', function () {
        pkgAdmin();
        $res = pkgUpload(PackageBuilder::good())->assertOk()->json('data');

        expect($res['valid'])->toBeTrue()
            ->and($res['stageToken'])->toMatch('/^[0-9a-z]{26}$/')
            ->and($res['expiresAt'])->not->toBeNull()
            ->and($res['report']['errors'])->toBe([])
            ->and($res['report']['manifest'])->toMatchArray(['slug' => 'botanical', 'version' => '1.0.0'])
            ->and($res['report']['stats'])->toHaveKeys(['files', 'runtimeBytes', 'firstFoldBytes'])
            ->and($res['report']['manifest'])->toHaveKeys(['thirdPartyMarks', 'assetsCredits', 'license', 'presets', 'eventTypes'])
            ->and($res['report']['manifest']['assetsCredits'])->toHaveCount(3)
            ->and(array_keys($res['report']['manifest']['presets']))->toBe(['salvia']);
        // The sanitized files wait next to the staging token; the original zip is never kept.
        Storage::disk('template-packages')->assertExists("_staging/{$res['stageToken']}/theme.css");
        Storage::disk('local')->assertExists("template-staging/{$res['stageToken']}.json");
        expect(AdminAuditLog::where('action', 'template.package_rejected')->count())->toBe(0);
    });

    it('answers 200 valid:false with the defects of the content, and audits the rejection (D-1)', function () {
        pkgAdmin();
        $res = pkgUpload(PackageBuilder::good()->with('theme.css', '@import url(//evil/x.css);'))->assertOk()->json('data');

        expect($res['valid'])->toBeFalse()
            ->and(array_column($res['report']['errors'], 'code'))->toContain('css_import');
        Storage::disk('template-packages')->assertMissing("_staging/{$res['stageToken']}/theme.css");
        $log = AdminAuditLog::where('action', 'template.package_rejected')->sole();
        expect($log->after['codes'])->toContain('css_import')->and($log->after['zipSha256'])->toHaveLength(64);
    });

    it('answers 422 errors.package[0] to a bad request, not to a bad package', function () {
        pkgAdmin();
        $this->post(PKG . '/validate', [], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonPath('errors.package.0', 'required');
        $this->post(PKG . '/validate', ['package' => UploadedFile::fake()->createWithContent('x.txt', 'hola')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonPath('errors.package.0', 'not_a_zip');
        $this->post(PKG . '/validate', ['package' => UploadedFile::fake()->create('big.zip', 9000, 'application/zip')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonPath('errors.package.0', 'too_large');
    });

    it('limits the uploads to 6 a minute (429 too_many_attempts)', function () {
        pkgAdmin();
        RateLimiter::clear('admin-template-package-upload');
        foreach (range(1, 6) as $i) {
            $this->post(PKG . '/validate', [], ['Accept' => 'application/json'])->assertUnprocessable();
        }
        $this->post(PKG . '/validate', [], ['Accept' => 'application/json'])->assertStatus(429)->assertJson(['code' => 'too_many_attempts']);
    });

    it('discards a stage, and answers 404 stage_not_found for an unknown or already discarded one', function () {
        pkgAdmin();
        $token = pkgUpload(PackageBuilder::good())->json('data.stageToken');

        $this->deleteJson(PKG . "/stages/{$token}")->assertOk()->assertJson(['data' => null]);
        Storage::disk('template-packages')->assertMissing("_staging/{$token}/theme.css");
        $this->deleteJson(PKG . "/stages/{$token}")->assertNotFound()->assertJson(['code' => 'stage_not_found']);
        $this->deleteJson(PKG . '/stages/not-a-token')->assertNotFound()->assertJson(['code' => 'stage_not_found']);
    });

    it('purges the stages that expired', function () {
        $admin = pkgAdmin();
        $old = pkgUpload(PackageBuilder::good())->json('data.stageToken');
        $this->travel(25)->hours();
        $this->actingAsReauthed($admin);
        $fresh = pkgUpload(pkgVersion('1.0.1'))->json('data.stageToken');

        $this->artisan('templates:purge-stages')->assertSuccessful();
        Storage::disk('local')->assertMissing("template-staging/{$old}.json");
        Storage::disk('template-packages')->assertMissing("_staging/{$old}/theme.css");
        Storage::disk('local')->assertExists("template-staging/{$fresh}.json");
    });
});

describe('install', function () {
    it('stores the package, creates the templates inactive with a draft owned by the package, and moves the files', function () {
        $admin = pkgAdmin();
        $token = pkgUpload(PackageBuilder::good())->json('data.stageToken');

        $pkg = $this->postJson(PKG, ['stage_token' => $token, 'reason' => 'Instalar la botánica'])->assertCreated()->json('data');

        expect($pkg)->toMatchArray(['slug' => 'botanical', 'semver' => '1.0.0', 'level' => 1, 'visibility' => 'public', 'tier' => 'premium', 'status' => 'installed', 'engineMin' => 1])
            ->and($pkg['templates'])->toHaveCount(2)
            ->and($pkg['installedBy']['id'])->toBe($admin->id)
            ->and($pkg['files'])->not->toBe([])
            ->and($pkg['report']['errors'])->toBe([]);

        $row = TemplatePackage::findOrFail($pkg['id']);
        expect($row->storage_path)->toMatch('#^botanical/1\.0\.0-[0-9a-f]{16}$#')
            ->and($row->content_hash)->toHaveLength(64)
            ->and(str_starts_with($row->storage_path, 'botanical/1.0.0-' . substr($row->content_hash, 0, 8)))->toBeTrue();
        $disk = Storage::disk('template-packages');
        $disk->assertExists("{$row->storage_path}/theme.css");
        $disk->assertExists("{$row->storage_path}/assets/img/wash.svg");
        $disk->assertExists("{$row->storage_path}/assets/fonts/script-400.woff2");
        $disk->assertMissing("{$row->storage_path}/manifest.json");
        $disk->assertMissing("{$row->storage_path}/preview/mobile.png");
        $disk->assertMissing("_staging/{$token}/theme.css");
        Storage::disk('local')->assertMissing("template-staging/{$token}.json");
        // The served CSS starts with the @font-face the server wrote.
        expect($disk->get("{$row->storage_path}/theme.css"))->toStartWith('@font-face{font-family:"Great Vibes"');

        foreach (['wedding', 'quinceanera'] as $type) {
            $template = InvitationTemplate::where('slug', "botanical-{$type}")->firstOrFail();
            $draft = $template->versions()->sole();
            expect($template->is_active)->toBeFalse()
                ->and($template->tier)->toBe('premium')
                ->and($template->eventType->slug)->toBe($type)
                ->and($draft->status)->toBe('draft')
                ->and($draft->package_id)->toBe($row->id)
                ->and($draft->theme['theme'])->toBe('botanical')
                ->and($draft->schema_version)->toBe(2);
        }

        $log = AdminAuditLog::where('action', 'template.package_installed')->sole();
        expect($log->reason)->toBe('Instalar la botánica')->and($log->after['slug'])->toBe('botanical')->and($log->actor_id)->toBe($admin->id);
    });

    it('is a single use token: installing twice answers 404, installing the same version again 409 package_exists', function () {
        pkgAdmin();
        $pkg = pkgInstall();
        pkgActivate($pkg['id']);
        $again = pkgUpload(PackageBuilder::good())->json('data');

        // The validator already refuses a version that is not greater.
        expect($again['valid'])->toBeFalse()->and(array_column($again['report']['errors'], 'code'))->toContain('version_not_greater');
        $this->postJson(PKG, ['stage_token' => $again['stageToken'], 'reason' => 'Otra vez más'])->assertUnprocessable()->assertJsonPath('errors.stage_token.0', 'invalid_report');

        $token = pkgUpload(pkgVersion('1.1.0'))->json('data.stageToken');
        $this->postJson(PKG, ['stage_token' => $token, 'reason' => 'Subir de versión'])->assertCreated();
        $this->postJson(PKG, ['stage_token' => $token, 'reason' => 'Subir de versión'])->assertNotFound()->assertJson(['code' => 'stage_not_found']);
        expect($pkg['id'])->toBeInt();
    });

    it('answers 409 package_exists when the same slug and version were installed in between', function () {
        pkgAdmin();
        $a = pkgUpload(pkgVersion('2.0.0'))->json('data.stageToken');
        $b = pkgUpload(pkgVersion('2.0.0'))->json('data.stageToken');
        $first = $this->postJson(PKG, ['stage_token' => $a, 'reason' => 'La primera'])->assertCreated()->json('data.id');

        $this->postJson(PKG, ['stage_token' => $b, 'reason' => 'La segunda'])->assertStatus(409)->assertJson(['code' => 'package_exists', 'packageId' => $first]);
        expect(TemplatePackage::count())->toBe(1);
    });

    it('answers 422 stale_stage when a newer version was installed after the upload', function () {
        pkgAdmin();
        $old = pkgUpload(pkgVersion('1.1.0'))->json('data.stageToken');
        pkgInstall(pkgVersion('1.2.0'));

        $this->postJson(PKG, ['stage_token' => $old, 'reason' => 'Ya no corresponde'])->assertUnprocessable()->assertJsonPath('errors.stage_token.0', 'stale_stage');
    });

    it('answers 410 stage_expired for a stage past its lifetime', function () {
        $admin = pkgAdmin();
        $token = pkgUpload(PackageBuilder::good())->json('data.stageToken');
        $this->travel(25)->hours();
        $this->actingAsReauthed($admin);

        $this->postJson(PKG, ['stage_token' => $token, 'reason' => 'Demasiado tarde'])->assertStatus(410)->assertJson(['code' => 'stage_expired']);
        Storage::disk('template-packages')->assertMissing("_staging/{$token}/theme.css");
    });

    it('answers 422 to a missing reason or token', function () {
        pkgAdmin();
        $this->postJson(PKG, [])->assertUnprocessable()->assertJsonPath('errors.stage_token.0', 'required')->assertJsonPath('errors.reason.0', 'required');
        $this->postJson(PKG, ['stage_token' => 'x', 'reason' => 'ok'])->assertUnprocessable()->assertJsonPath('errors.reason.0', 'invalid');
    });

    it('is all or nothing: a draft already open in one template leaves no row and no file behind (409 draft_exists)', function () {
        pkgAdmin();
        // The quinceañera template of the package already has a draft in the middle of the install.
        $template = InvitationTemplate::create(['slug' => 'botanical-quinceanera', 'name' => 'x', 'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'), 'tier' => 'free', 'default_sections' => [], 'theme_defaults' => [], 'is_active' => false]);
        $template->versions()->create(['version' => 1, 'status' => 'draft', 'sections' => [], 'theme' => []]);
        $token = pkgUpload(PackageBuilder::good())->json('data.stageToken');

        $this->postJson(PKG, ['stage_token' => $token, 'reason' => 'Chocar con un borrador'])->assertStatus(409)->assertJson(['code' => 'draft_exists']);

        expect(TemplatePackage::count())->toBe(0)
            ->and(InvitationTemplate::where('slug', 'botanical-wedding')->exists())->toBeFalse()
            ->and(Storage::disk('template-packages')->allFiles('botanical'))->toBe([])
            ->and(AdminAuditLog::where('action', 'template.package_installed')->count())->toBe(0);
    });

    it('refuses to change the visibility of a slug across versions (visibility_changed)', function () {
        pkgAdmin();
        pkgInstall(PackageBuilder::good()->json('manifest.json', fn ($m) => [...$m, 'visibility' => 'private']));

        $res = pkgUpload(pkgVersion('1.1.0'))->json('data');
        expect($res['valid'])->toBeFalse()->and(array_column($res['report']['errors'], 'code'))->toContain('visibility_changed');
    });

    it('refuses an engineMin above the engine of the environment (engine_too_new)', function () {
        pkgAdmin();
        config(['invitation.engine' => 0]);

        expect(array_column(pkgUpload(PackageBuilder::good())->json('data.report.errors'), 'code'))->toContain('engine_too_new');
    });

    it('limits installs to 10 a minute', function () {
        pkgAdmin();
        RateLimiter::clear('admin-template-package-install');
        foreach (range(1, 10) as $i) {
            $this->postJson(PKG, [])->assertUnprocessable();
        }
        $this->postJson(PKG, [])->assertStatus(429)->assertJson(['code' => 'too_many_attempts']);
    });
});

describe('list and detail', function () {
    it('lists packages with the summary, filters and pagination', function () {
        pkgAdmin();
        $a = pkgInstall();
        pkgActivate($a['id']);
        pkgInstall(pkgVersion('1.1.0'));

        $list = $this->getJson(PKG)->assertOk()->json();
        expect($list['data'])->toHaveCount(2)
            ->and($list['data'][0]['semver'])->toBe('1.1.0')
            ->and($list['data'][0])->toHaveKeys(['id', 'slug', 'semver', 'level', 'visibility', 'tier', 'status', 'name', 'eventTypes', 'engineMin', 'author', 'sizeBytes', 'installedAt', 'eventsPinned'])
            ->and($list['data'][0]['name'])->toBe(['es' => 'Botánica acuarela', 'en' => 'Watercolor botanical'])
            ->and($list['meta'])->toMatchArray(['page' => 1, 'total' => 2]);
        expect($this->getJson(PKG . '?status=active')->json('data'))->toHaveCount(1)
            ->and($this->getJson(PKG . '?visibility=private')->json('data'))->toBe([])
            ->and($this->getJson(PKG . '?per_page=1&page=2')->json('data.0.semver'))->toBe('1.0.0');
        $this->getJson(PKG . '?status=bogus')->assertUnprocessable()->assertJsonPath('errors.status.0', 'invalid');
    });

    it('shows the detail, and 404 package_not_found for an unknown id', function () {
        pkgAdmin();
        $pkg = pkgInstall();

        $detail = $this->getJson(PKG . "/{$pkg['id']}")->assertOk()->json('data');
        expect($detail)->toHaveKeys(['manifest', 'credits', 'thirdPartyMarks', 'editable', 'files', 'report', 'templates', 'eventsPinned', 'contentHash'])
            ->and($detail['credits'])->toHaveCount(3);

        $this->getJson(PKG . '/999999')->assertNotFound()->assertJson(['code' => 'package_not_found']);
    });
});

describe('activate, deactivate, withdraw', function () {
    it('publishes the drafts, turns the templates on and marks the package active', function () {
        pkgAdmin();
        $pkg = pkgInstall();

        $res = pkgActivate($pkg['id']);

        expect($res['package']['status'])->toBe('active')->and($res['eventsOnPreviousVersion'])->toBe(0);
        foreach (['wedding', 'quinceanera'] as $type) {
            $template = InvitationTemplate::where('slug', "botanical-{$type}")->firstOrFail();
            expect($template->is_active)->toBeTrue()
                ->and($template->currentVersion->status)->toBe('published')
                ->and($template->currentVersion->package_id)->toBe($pkg['id']);
        }
        expect(AdminAuditLog::where('action', 'template.package_activated')->sole()->reason)->toBe('Activar tras revisar');
        $this->postJson(PKG . "/{$pkg['id']}/activate", ['reason' => 'Activar otra vez', 'checklist' => PKG_CHECKLIST])->assertStatus(409)->assertJson(['code' => 'package_not_installable']);
    });

    it('needs the whole quality checklist to activate, and audits who confirmed it and when (422 errors.checklist[0]=incomplete)', function () {
        $admin = pkgAdmin();
        $pkg = pkgInstall();
        $url = PKG . "/{$pkg['id']}/activate";

        $this->postJson($url, ['reason' => 'Sin revisión completa'])->assertUnprocessable()->assertJsonPath('errors.checklist.0', 'incomplete');
        $this->postJson($url, ['reason' => 'Sin revisión completa', 'checklist' => ['accessibility' => true]])->assertUnprocessable()->assertJsonPath('errors.checklist.0', 'incomplete');
        $this->postJson($url, ['reason' => 'Sin revisión completa', 'checklist' => [...PKG_CHECKLIST, 'performance' => false]])->assertUnprocessable()->assertJsonPath('errors.checklist.0', 'incomplete');
        $this->postJson($url, ['reason' => 'Sin revisión completa', 'checklist' => [...PKG_CHECKLIST, 'performance' => 'yes']])->assertUnprocessable()->assertJsonPath('errors.checklist.0', 'incomplete');
        expect(TemplatePackage::find($pkg['id'])->status)->toBe('installed');

        $this->postJson($url, ['reason' => 'Revisión completa', 'checklist' => PKG_CHECKLIST])->assertOk();
        $log = AdminAuditLog::where('action', 'template.package_activated')->sole();
        expect($log->actor_id)->toBe($admin->id)->and($log->after['checklist'])->toBe(PKG_CHECKLIST)->and($log->after['confirmedAt'])->not->toBeNull();
    });

    it('needs the acknowledgement of third party marks to activate a private package that declares them', function () {
        pkgAdmin();
        $pkg = pkgInstall(PackageBuilder::good()->json('manifest.json', fn ($m) => [...$m, 'visibility' => 'private', 'thirdPartyMarks' => [['name' => 'ACME', 'owner' => 'ACME Inc', 'usage' => 'logo en la portada']]]));

        $this->postJson(PKG . "/{$pkg['id']}/activate", ['reason' => 'Revisado con el cliente', 'checklist' => PKG_CHECKLIST])->assertUnprocessable()->assertJsonPath('errors.ack_third_party_marks.0', 'required');
        $this->postJson(PKG . "/{$pkg['id']}/activate", ['reason' => 'Revisado con el cliente', 'checklist' => PKG_CHECKLIST, 'ack_third_party_marks' => true])->assertOk();
        expect(AdminAuditLog::where('action', 'template.package_activated')->sole()->after['ackThirdPartyMarks'])->toBeTrue();
    });

    it('refuses to activate when the engine is older than the package asks (422 errors.package[0]=engine_too_new)', function () {
        pkgAdmin();
        $pkg = pkgInstall();
        config(['invitation.engine' => 0]);

        $this->postJson(PKG . "/{$pkg['id']}/activate", ['reason' => 'Probar con otro motor', 'checklist' => PKG_CHECKLIST])->assertUnprocessable()->assertJsonPath('errors.package.0', 'engine_too_new');
    });

    it('keeps the events published before on their version when a newer one is activated', function () {
        pkgAdmin();
        $v1 = pkgInstall();
        pkgActivate($v1['id']);
        $event = pkgPublish(pkgEvent('boda-camila'));
        $pinned = $event->fresh()->template_version_id;
        expect(InvitationTemplateVersion::find($pinned)->package_id)->toBe($v1['id']);

        $v2 = pkgInstall(pkgVersion('1.1.0'));
        $res = pkgActivate($v2['id']);

        expect($res['eventsOnPreviousVersion'])->toBe(1)
            ->and($event->fresh()->template_version_id)->toBe($pinned)
            ->and(TemplatePackage::find($v1['id'])->status)->toBe('deactivated')
            ->and(TemplatePackage::find($v2['id'])->status)->toBe('active');
        $theme = $this->getJson('/api/v1/app/invitation/boda-camila/PKG123')->assertOk()->json('data.theme');
        expect($theme['package']['version'])->toBe('1.0.0');
        // And the old package cannot be withdrawn while the event is fixed to it.
        $this->deleteJson(PKG . "/{$v1['id']}", ['reason' => 'Intentar retirarla'])->assertStatus(409)->assertJson(['code' => 'package_in_use', 'eventsPinned' => 1]);
    });

    it('reports the impact before deactivating and sends unpublished events back to the free template (D-5)', function () {
        pkgAdmin();
        $pkg = pkgInstall();
        pkgActivate($pkg['id']);
        $published = pkgPublish(pkgEvent('boda-uno'));
        $draft = pkgEvent('boda-dos');

        $impact = $this->getJson(PKG . "/{$pkg['id']}/impact?action=deactivate")->assertOk()->json('data');
        expect($impact)->toMatchArray(['eventsPinned' => 1, 'eventsUnpublished' => 1])->and($impact['templates'])->toHaveCount(2)
            ->and(collect($impact['templates'])->pluck('eventsUnpublished', 'slug')->all())->toBe(['botanical-wedding' => 1, 'botanical-quinceanera' => 0]);
        $this->getJson(PKG . "/{$pkg['id']}/impact")->assertUnprocessable()->assertJsonPath('errors.action.0', 'required');
        $this->getJson(PKG . "/{$pkg['id']}/impact?action=x")->assertUnprocessable()->assertJsonPath('errors.action.0', 'invalid');

        $this->postJson(PKG . "/{$pkg['id']}/deactivate", ['reason' => 'Pausar el paquete'])->assertOk()->assertJsonPath('data.status', 'deactivated');
        expect(InvitationTemplate::where('slug', 'botanical-wedding')->value('is_active'))->toBeFalse();
        // Published: unchanged. Not published: Classic.
        expect($this->getJson('/api/v1/app/invitation/boda-uno/PKG123')->json('data.theme.package.slug'))->toBe('botanical');
        $draft->forceFill(['status' => 'published', 'published_at' => now()])->save();
        expect($this->getJson('/api/v1/app/invitation/boda-dos/PKG123')->json('data.theme.name'))->toBe('classic');
        expect($published->fresh()->template_version_id)->not->toBeNull();

        $this->postJson(PKG . "/{$pkg['id']}/deactivate", ['reason' => 'Pausar el paquete'])->assertStatus(409)->assertJson(['code' => 'package_not_active']);
        expect(AdminAuditLog::where('action', 'template.package_deactivated')->sole()->after)->toMatchArray(['eventsPinned' => 1, 'eventsUnpublished' => 1]);
    });

    it('re-activates a deactivated package', function () {
        pkgAdmin();
        $pkg = pkgInstall();
        pkgActivate($pkg['id']);
        $this->postJson(PKG . "/{$pkg['id']}/deactivate", ['reason' => 'Pausar el paquete'])->assertOk();

        $this->postJson(PKG . "/{$pkg['id']}/activate", ['reason' => 'Volver a encender', 'checklist' => PKG_CHECKLIST])->assertOk()->assertJsonPath('data.package.status', 'active');
        expect(InvitationTemplate::where('slug', 'botanical-wedding')->value('is_active'))->toBeTrue();
    });

    it('withdraws a package nobody uses: files gone, rows archived, templates off', function () {
        pkgAdmin();
        $pkg = pkgInstall();
        pkgActivate($pkg['id']);
        $folder = TemplatePackage::find($pkg['id'])->storage_path;

        $this->deleteJson(PKG . "/{$pkg['id']}", ['reason' => 'Se retira la plantilla'])->assertOk()->assertJson(['data' => null]);

        expect(TemplatePackage::find($pkg['id'])->status)->toBe('withdrawn')
            ->and(TemplatePackage::find($pkg['id'])->withdrawn_at)->not->toBeNull()
            ->and(InvitationTemplateVersion::where('package_id', $pkg['id'])->pluck('status')->unique()->all())->toBe(['archived'])
            ->and(InvitationTemplate::where('slug', 'botanical-wedding')->value('is_active'))->toBeFalse()
            ->and(Storage::disk('template-packages')->exists("{$folder}/theme.css"))->toBeFalse()
            ->and(AdminAuditLog::where('action', 'template.package_withdrawn')->count())->toBe(1);
        $this->deleteJson(PKG . "/{$pkg['id']}", ['reason' => 'Se retira otra vez'])->assertStatus(409)->assertJson(['code' => 'package_withdrawn']);
        // The row stays as a record and its version cannot come back.
        $this->postJson(PKG . "/{$pkg['id']}/activate", ['reason' => 'Intentar volver', 'checklist' => PKG_CHECKLIST])->assertStatus(409)->assertJson(['code' => 'package_not_installable']);
    });

    it('limits activate, deactivate and withdraw to 10 a minute together', function () {
        pkgAdmin();
        RateLimiter::clear('admin-template-package-change');
        foreach (range(1, 10) as $i) {
            $this->postJson(PKG . '/1/activate', [])->assertUnprocessable();
        }
        $this->postJson(PKG . '/1/deactivate', [])->assertStatus(429)->assertJson(['code' => 'too_many_attempts']);
    });
});

describe('immutability of versions owned by a package (D-4)', function () {
    it('answers 409 package_managed to a PATCH of sections or theme and to a hand-made draft', function () {
        pkgAdmin();
        $pkg = pkgInstall();
        $draft = InvitationTemplateVersion::where('package_id', $pkg['id'])->firstOrFail();

        $this->patchJson("/api/v1/admin/templates/versions/{$draft->id}", ['sections' => $draft->sections])->assertStatus(409)->assertJson(['code' => 'package_managed']);
        $this->patchJson("/api/v1/admin/templates/versions/{$draft->id}", ['theme' => $draft->theme])->assertStatus(409)->assertJson(['code' => 'package_managed']);
        expect(fn () => $draft->update(['notes' => 'a mano']))->toThrow(LogicException::class);

        pkgActivate($pkg['id']);
        $templateId = $draft->template_id;
        $this->postJson("/api/v1/admin/templates/{$templateId}/versions")->assertStatus(409)->assertJson(['code' => 'package_managed']);
    });

    it('never lets an installed package change its content', function () {
        pkgAdmin();
        $pkg = TemplatePackage::findOrFail(pkgInstall()['id']);

        expect(fn () => TemplatePackage::findOrFail($pkg->id)->update(['manifest' => []]))->toThrow(LogicException::class)
            ->and(fn () => TemplatePackage::findOrFail($pkg->id)->update(['content_hash' => str_repeat('0', 64)]))->toThrow(LogicException::class);
        $pkg->update(['status' => 'deactivated']);
        expect($pkg->fresh()->status)->toBe('deactivated');
    });

    it('exposes the owning package in the template and version listings', function () {
        pkgAdmin();
        $pkg = pkgInstall();
        pkgActivate($pkg['id']);

        $row = collect($this->getJson('/api/v1/admin/templates')->json('data'))->firstWhere('slug', 'botanical-wedding');
        expect($row['currentVersion']['packageId'])->toBe($pkg['id']);
        expect($this->getJson('/api/v1/admin/templates/' . $row['id'])->json('data.versions.0.packageId'))->toBe($pkg['id']);
        expect($this->getJson('/api/v1/admin/templates/versions/' . $row['currentVersion']['id'])->json('data.packageId'))->toBe($pkg['id']);
    });
});

describe('publicly visible payload', function () {
    it('adds theme.package and the AST of the sections the package draws', function () {
        pkgAdmin();
        $pkg = pkgInstall();
        pkgActivate($pkg['id']);
        pkgPublish(pkgEvent('boda-camila'));
        $folder = TemplatePackage::find($pkg['id'])->storage_path;

        $data = $this->getJson('/api/v1/app/invitation/boda-camila/PKG123')->assertOk()->json('data');
        $package = $data['theme']['package'];

        expect($data['theme']['name'])->toBe('botanical')
            ->and($package)->toMatchArray(['slug' => 'botanical', 'version' => '1.0.0', 'level' => 1, 'engineMin' => 1])
            ->and($package['hash'])->toBe(TemplatePackage::find($pkg['id'])->content_hash)
            ->and($package['cssUrl'])->toEndWith("{$folder}/theme.css")
            ->and($package['preload'])->toHaveCount(1)
            ->and($package['preload'][0])->toMatchArray(['as' => 'font', 'type' => 'font/woff2'])
            ->and($package['preload'][0]['url'])->toEndWith("{$folder}/assets/fonts/script-400.woff2")
            ->and(array_keys($package['templates']))->toEqualCanonicalizing(['cover', 'rsvp', 'closing'])
            ->and($package['templates']['cover']['kind'])->toBe('section')
            ->and($package['strings']['es']['invitedFor'])->toBe('Invitación para')
            ->and($package['layout']['kind'])->toBe('layout')
            ->and($package['options'])->toBe([]);

        $byType = collect($data['sections'])->keyBy('type');
        expect($byType['cover'])->toMatchArray(['render' => 'package'])->and($byType['cover'])->not->toHaveKey('ast')
            ->and($byType['rsvp']['render'])->toBe('package')
            ->and($byType['closing']['render'])->toBe('package');
        // The data of the sections is untouched: the package only decides how it is drawn.
        expect($byType['rsvp']['data'])->toHaveKey('guest');
    });

    it('keeps a section the package does not draw on the engine components', function () {
        pkgAdmin();
        $pkg = pkgInstall(PackageBuilder::good()->without('sections/closing.html'));
        pkgActivate($pkg['id']);
        pkgPublish(pkgEvent('boda-camila'));

        $closing = collect($this->getJson('/api/v1/app/invitation/boda-camila/PKG123')->json('data.sections'))->firstWhere('type', 'closing');
        expect($closing)->not->toHaveKeys(['render', 'ast']);
        expect($this->getJson('/api/v1/app/invitation/boda-camila/PKG123')->json('data.theme.package.templates'))->not->toHaveKey('closing');
    });

    it('serves the package through the options of the event only for options the package defines', function () {
        pkgAdmin();
        $pkg = pkgInstall(PackageBuilder::good()
            ->json('manifest.json', fn ($m) => [...$m, 'variants' => ['cover.layout' => ['values' => ['arch', 'bleed'], 'default' => 'bleed']]]));
        pkgActivate($pkg['id']);
        $event = pkgPublish(pkgEvent('boda-camila'));
        expect($this->getJson('/api/v1/app/invitation/boda-camila/PKG123')->json('data.theme.package.options'))->toBe(['cover.layout' => 'bleed']);

        $event->forceFill(['template_config' => ['package' => ['options' => ['cover.layout' => 'arch', 'evil' => 'x']]]])->save();
        expect($this->getJson('/api/v1/app/invitation/boda-camila/PKG123')->json('data.theme.package.options'))->toBe(['cover.layout' => 'arch']);
    });

    it('falls back to Classic when the package row is withdrawn, or the engine is older than engineMin', function () {
        pkgAdmin();
        $pkg = pkgInstall();
        pkgActivate($pkg['id']);
        pkgPublish(pkgEvent('boda-camila'));
        Cache::flush();

        config(['invitation.engine' => 0]);
        $data = $this->getJson('/api/v1/app/invitation/boda-camila/PKG123')->assertOk()->json('data');
        expect($data['theme']['name'])->toBe('classic')
            ->and($data['theme'])->not->toHaveKey('package')
            ->and($data['theme']['colors']['primary'])->toBe('#566f52') // the colors and fonts of the saved theme remain
            ->and(array_filter($data['sections'], fn ($s) => isset($s['render'])))->toBe([]);

        config(['invitation.engine' => 1]);
        TemplatePackage::whereKey($pkg['id'])->update(['status' => 'withdrawn']);
        $data = $this->getJson('/api/v1/app/invitation/boda-camila/PKG123')->json('data');
        expect($data['theme']['name'])->toBe('classic')->and($data['theme'])->not->toHaveKey('package');
    });

    it('lets the Save the date draw with the package too', function () {
        pkgAdmin();
        $pkg = pkgInstall();
        pkgActivate($pkg['id']);
        $event = pkgPublish(pkgEvent('boda-camila'));
        $event->forceFill(['invitation_stage' => Events::STAGE_SAVE_THE_DATE])->save();

        $data = $this->getJson('/api/v1/app/invitation/boda-camila/PKG123')->assertOk()->json('data');
        $types = collect($data['sections'])->pluck('render', 'type')->all();
        expect($data['stage'])->toBe('save_the_date')->and($types['cover'] ?? null)->toBe('package')->and($types['closing'] ?? null)->toBe('package');
    });

    it('does not change what Classic events get (no theme.package, engine sections)', function () {
        $event = Events::factory()->create(['event_type_id' => EventType::where('slug', 'quinceanera')->value('id'), 'status' => 'published', 'custom_url_slug' => 'xv-uno', 'event_plan_id' => EventPlan::where('slug', 'premium')->value('id')]);
        Guest::factory()->create(['event_id' => $event->id, 'code' => 'CLS123', 'parent_id' => null]);

        $data = $this->getJson('/api/v1/app/invitation/xv-uno/CLS123')->assertOk()->json('data');
        expect($data['theme']['name'])->toBe('classic')->and($data['theme'])->not->toHaveKey('package')
            ->and(array_filter($data['sections'], fn ($s) => isset($s['render'])))->toBe([]);
    });
});

describe('preview', function () {
    it('draws a staged upload with the files of the stage and the sample data', function () {
        pkgAdmin();
        $token = pkgUpload(PackageBuilder::good())->json('data.stageToken');

        $data = $this->postJson('/api/v1/admin/templates/preview', ['stage_token' => $token, 'locale' => 'es', 'eventType' => 'wedding'])->assertOk()->json('data');

        expect($data['theme']['name'])->toBe('botanical')
            ->and($data['theme']['package']['cssUrl'])->toContain("_staging/{$token}/theme.css")
            ->and($data['stage'])->toBe('invitation')
            ->and(collect($data['sections'])->pluck('render', 'type')->all())->toMatchArray(['cover' => 'package', 'rsvp' => 'package']);
    });

    it('supports the Save the date and no photo states and a preset of the package', function () {
        pkgAdmin();
        $token = pkgUpload(PackageBuilder::good()->json('tokens.json', function ($t) {
            $t['presets']['oliva'] = ['label' => ['es' => 'Oliva', 'en' => 'Olive'], 'colors' => [...$t['presets']['salvia']['colors'], 'primary' => '#44552f']];

            return $t;
        }))->json('data.stageToken');
        $body = ['stage_token' => $token, 'locale' => 'en', 'eventType' => 'quinceanera'];

        $std = $this->postJson('/api/v1/admin/templates/preview', $body + ['state' => 'save_the_date'])->assertOk()->json('data');
        expect($std['stage'])->toBe('save_the_date')->and(array_column($std['sections'], 'type'))->toBe(['cover', 'countdown', 'save_the_date', 'closing'])
            ->and($std['sections'][0]['title'])->toBe('Save the date');

        $none = $this->postJson('/api/v1/admin/templates/preview', $body + ['state' => 'no_photo'])->assertOk()->json('data');
        expect($none['sections'][0]['data']['image'])->toBeNull();

        $preset = $this->postJson('/api/v1/admin/templates/preview', $body + ['preset' => 'oliva'])->assertOk()->json('data');
        expect($preset['theme']['preset'])->toBe('oliva')->and($preset['theme']['colors']['primary'])->toBe('#44552f');
        $this->postJson('/api/v1/admin/templates/preview', $body + ['preset' => 'nope'])->assertUnprocessable()->assertJsonPath('errors.preset.0', 'invalid');
    });

    it('draws an installed package by id, and answers 404 / 422 for the unknown or invalid', function () {
        pkgAdmin();
        $pkg = pkgInstall();

        $data = $this->postJson('/api/v1/admin/templates/preview', ['package_id' => $pkg['id'], 'locale' => 'es', 'eventType' => 'wedding'])->assertOk()->json('data');
        expect($data['theme']['package']['cssUrl'])->toContain(TemplatePackage::find($pkg['id'])->storage_path);

        $this->postJson('/api/v1/admin/templates/preview', ['stage_token' => str_repeat('a', 26), 'locale' => 'es', 'eventType' => 'wedding'])->assertNotFound()->assertJson(['code' => 'stage_not_found']);
        $bad = pkgUpload(PackageBuilder::good()->with('theme.css', '@import "x";'))->json('data.stageToken');
        $this->postJson('/api/v1/admin/templates/preview', ['stage_token' => $bad, 'locale' => 'es', 'eventType' => 'wedding'])->assertUnprocessable()->assertJsonPath('errors.stage_token.0', 'invalid_report');
    });

    it('answers 422 not_supported for an event type the package does not declare', function () {
        pkgAdmin();
        $token = pkgUpload(PackageBuilder::good()->json('manifest.json', fn ($m) => [...$m, 'eventTypes' => ['wedding']])->json('sections.json', fn ($s) => ['wedding' => $s['wedding']]))->json('data.stageToken');

        $this->postJson('/api/v1/admin/templates/preview', ['stage_token' => $token, 'locale' => 'es', 'eventType' => 'quinceanera'])->assertUnprocessable()->assertJsonPath('errors.eventType.0', 'not_supported');
    });

    it('keeps the previous behaviour for sections and theme sent in the request', function () {
        pkgAdmin();
        $this->postJson('/api/v1/admin/templates/preview', ['locale' => 'es', 'eventType' => 'wedding'])->assertUnprocessable();
    });
});

describe('template schema for packages', function () {
    it('accepts the theme of an installed package, its font families and the wash color, and nothing else', function () {
        pkgAdmin();
        pkgInstall(PackageBuilder::good()->json('tokens.json', function ($t) {
            $t['fonts']['heading'] = ['family' => 'Great Vibes', 'files' => $t['fonts']['script']['files']];

            return $t;
        }));
        $version = InvitationTemplateVersion::where('package_id', '!=', null)->firstOrFail();

        $ok = fn (array $theme) => $this->postJson('/api/v1/admin/templates/preview', ['sections' => $version->sections, 'theme' => $theme, 'locale' => 'es', 'eventType' => 'wedding']);
        $ok($version->theme)->assertOk();

        $ok(['theme' => 'unknown-theme'] + $version->theme)->assertUnprocessable()->assertJsonValidationErrors(['theme.theme']);
        $badWash = $version->theme;
        $badWash['presets']['salvia']['colors']['wash'] = 'peach';
        $ok($badWash)->assertUnprocessable()->assertJsonValidationErrors(['theme.presets.salvia.colors.wash']);
        // Another theme's family is not accepted just because a package declares it.
        $classic = InvitationTemplate::where('slug', 'classic-quinceanera')->firstOrFail()->currentVersion;
        $this->postJson('/api/v1/admin/templates/preview', ['sections' => $classic->sections, 'theme' => ['fonts' => ['heading' => 'Comic Sans']] + $classic->theme, 'locale' => 'es', 'eventType' => 'quinceanera'])
            ->assertUnprocessable()->assertJsonValidationErrors(['theme.fonts.heading']);
    });

    it('accepts the focus of the cover photo and refuses a cover that is not first (D-15)', function () {
        pkgAdmin();
        $classic = InvitationTemplate::where('slug', 'classic-quinceanera')->firstOrFail()->currentVersion;
        $send = fn (array $sections) => $this->postJson('/api/v1/admin/templates/preview', ['sections' => $sections, 'theme' => $classic->theme, 'locale' => 'es', 'eventType' => 'quinceanera']);

        $sections = $classic->sections;
        $sections[0]['settings']['focus'] = '50% 30%';
        $send($sections)->assertOk();
        $sections[0]['settings']['focus'] = 'center';
        $send($sections)->assertUnprocessable()->assertJsonValidationErrors(['sections.0.settings.focus']);

        $sections = $classic->sections;
        $sections[1]['order'] = $sections[0]['order'];
        $send($sections)->assertUnprocessable()->assertJsonValidationErrors(['sections.0.order']);
    });
});

describe('catalog', function () {
    it('offers Classic and public active packages, never a private one (grants arrive with stage 6)', function () {
        pkgAdmin();
        $public = pkgInstall();
        pkgActivate($public['id']);
        $private = pkgInstall(PackageBuilder::good()->json('manifest.json', fn ($m) => [...$m, 'slug' => 'vip-noir', 'visibility' => 'private']));
        pkgActivate($private['id']);

        $slugs = app(TemplateCatalog::class)->availableFor(null, null)->pluck('slug')->all();
        expect($slugs)->toContain('classic-quinceanera', 'classic-wedding', 'botanical-wedding', 'botanical-quinceanera')
            ->and($slugs)->not->toContain('vip-noir-wedding', 'vip-noir-quinceanera');

        $event = Events::factory()->create(['event_type_id' => EventType::where('slug', 'wedding')->value('id')]);
        $forWedding = app(TemplateCatalog::class)->availableFor(null, $event)->pluck('slug')->all();
        expect($forWedding)->toContain('botanical-wedding')->and($forWedding)->not->toContain('botanical-quinceanera');
    });

    it('falls back to the free template for an event that follows a private package', function () {
        pkgAdmin();
        $private = pkgInstall(PackageBuilder::good()->json('manifest.json', fn ($m) => [...$m, 'slug' => 'vip-noir', 'visibility' => 'private']));
        pkgActivate($private['id']);
        $event = pkgPublish(Events::factory()->draft()->create([
            'event_type_id' => EventType::where('slug', 'wedding')->value('id'),
            'event_plan_id' => EventPlan::where('slug', 'premium')->value('id'),
            'custom_url_slug' => 'boda-vip',
            'start_date' => now()->addMonths(3),
            'template_id' => InvitationTemplate::where('slug', 'vip-noir-wedding')->value('id'),
        ]));
        Guest::factory()->create(['event_id' => $event->id, 'code' => 'VIP123', 'parent_id' => null]);

        expect(InvitationTemplateVersion::find($event->fresh()->template_version_id)->package_id)->toBeNull();
    });
});

describe('EventTemplateConfigValidator', function () {
    function cfgPackage(?callable $edit = null): TemplatePackage
    {
        $editable = [
            'schema' => 1,
            'groups' => [['id' => 'cover', 'label' => ['es' => 'Portada', 'en' => 'Cover']]],
            'fields' => [
                ['id' => 'cover_photo', 'type' => 'image', 'group' => 'cover', 'label' => ['es' => 'Foto', 'en' => 'Photo'], 'bind' => ['section' => 'cover', 'setting' => 'image', 'focus' => 'focus'], 'plan' => 'free'],
                ['id' => 'cover_phrase', 'type' => 'text', 'group' => 'cover', 'label' => ['es' => 'Frase', 'en' => 'Phrase'], 'bind' => ['section' => 'cover', 'field' => 'text'], 'max' => ['es' => 20, 'en' => 20], 'plan' => 'free'],
                ['id' => 'color_primary', 'type' => 'color', 'label' => ['es' => 'Color', 'en' => 'Color'], 'bind' => ['theme' => 'colors.primary'], 'plan' => 'full'],
                ['id' => 'layout', 'type' => 'choice', 'label' => ['es' => 'Portada', 'en' => 'Cover'], 'bind' => ['option' => 'cover.layout'], 'choices' => [['value' => 'arch', 'label' => ['es' => 'a', 'en' => 'a']], ['value' => 'bleed', 'label' => ['es' => 'b', 'en' => 'b']]], 'default' => 'bleed', 'plan' => 'full'],
                ['id' => 'ornaments', 'type' => 'toggle', 'label' => ['es' => 'Adornos', 'en' => 'Ornaments'], 'bind' => ['option' => 'ornaments'], 'default' => true, 'plan' => 'free'],
                ['id' => 'font', 'type' => 'choice', 'label' => ['es' => 'Letra', 'en' => 'Font'], 'bind' => ['theme' => 'fonts.script'], 'choices' => [['value' => 'Great Vibes', 'label' => ['es' => 'a', 'en' => 'a']]], 'plan' => 'free'],
            ],
        ];

        return new TemplatePackage(['editable' => $edit ? $edit($editable) : $editable]);
    }

    function cfgErrors(array $config, bool $unlocked = true, ?TemplatePackage $package = null): array
    {
        try {
            (new EventTemplateConfigValidator)->validate($config, $package ?? cfgPackage(), $unlocked);
        } catch (ValidationException $e) {
            return array_map(fn ($m) => $m[0], $e->errors());
        }

        return [];
    }

    it('accepts what the package exposes', function () {
        expect(cfgErrors([
            'sections' => [['type' => 'cover', 'settings' => ['image' => '/storage/events/1/cover.jpg', 'focus' => '40% 25%'], 'text' => ['es' => 'Te espero', 'en' => 'See you']]],
            'theme' => ['colors' => ['primary' => '#112233'], 'fonts' => ['script' => 'Great Vibes']],
            'package' => ['options' => ['cover.layout' => 'arch', 'ornaments' => false]],
        ]))->toBe([])->and(cfgErrors([]))->toBe([]);
    });

    it('answers each code of the contract', function (array $config, string $path, string $code) {
        expect(cfgErrors($config))->toMatchArray([$path => $code]);
    })->with([
        'unknown top level key' => [['evil' => 1], 'evil', 'unknown_field'],
        'field the package does not expose' => [['theme' => ['colors' => ['accent' => '#112233']]], 'theme.colors.accent', 'unknown_field'],
        'section setting not exposed' => [['sections' => [['type' => 'rsvp', 'settings' => ['image' => '/a.jpg']]]], 'sections.0.settings.image', 'unknown_field'],
        'text field not exposed' => [['sections' => [['type' => 'cover', 'title' => ['es' => 'x']]]], 'sections.0.title', 'unknown_field'],
        'option not defined' => [['package' => ['options' => ['hack' => true]]], 'package.options.hack', 'unknown_field'],
        'image from another site' => [['sections' => [['type' => 'cover', 'settings' => ['image' => 'https://evil.example/a.png']]]], 'sections.0.settings.image', 'invalid_type'],
        'image path with ..' => [['sections' => [['type' => 'cover', 'settings' => ['image' => '/a/../b.jpg']]]], 'sections.0.settings.image', 'invalid_type'],
        'toggle that is not a boolean' => [['package' => ['options' => ['ornaments' => 'yes']]], 'package.options.ornaments', 'invalid_type'],
        'html in a text' => [['sections' => [['type' => 'cover', 'text' => ['es' => '<b>x</b>']]]], 'sections.0.text.es', 'invalid_type'],
        'color that is not hex' => [['theme' => ['colors' => ['primary' => 'red']]], 'theme.colors.primary', 'invalid_color'],
        'text too long' => [['sections' => [['type' => 'cover', 'text' => ['es' => str_repeat('a', 21)]]]], 'sections.0.text.es', 'too_long'],
        'choice outside the list' => [['package' => ['options' => ['cover.layout' => 'wide']]], 'package.options.cover.layout', 'invalid_choice'],
        'font outside the list' => [['theme' => ['fonts' => ['script' => 'Comic Sans']]], 'theme.fonts.script', 'invalid_choice'],
        'bad focus' => [['sections' => [['type' => 'cover', 'settings' => ['focus' => 'left']]]], 'sections.0.settings.focus', 'invalid_focus'],
        'language that does not exist' => [['sections' => [['type' => 'cover', 'text' => ['fr' => 'x']]]], 'sections.0.text.fr', 'unknown_field'],
    ]);

    it('blocks plan: full fields on a locked event with 403 plan_required, and lets the free ones through', function () {
        $free = ['sections' => [['type' => 'cover', 'text' => ['es' => 'Hola']]], 'package' => ['options' => ['ornaments' => false]]];
        expect(cfgErrors($free, unlocked: false))->toBe([]);

        foreach ([['theme' => ['colors' => ['primary' => '#112233']]], ['package' => ['options' => ['cover.layout' => 'arch']]]] as $full) {
            try {
                (new EventTemplateConfigValidator)->validate($full, cfgPackage(), false);
                $this->fail('expected a 403');
            } catch (HttpResponseException $e) {
                expect($e->getResponse()->getStatusCode())->toBe(403)->and(json_decode($e->getResponse()->getContent(), true)['code'])->toBe('plan_required');
            }
        }
        expect(cfgErrors(['theme' => ['colors' => ['primary' => '#112233']]], unlocked: true))->toBe([]);
    });

    it('finds the package of an event from its pinned version or its template', function () {
        pkgAdmin();
        $pkg = pkgInstall();
        pkgActivate($pkg['id']);
        $draft = pkgEvent('boda-camila');
        $published = pkgPublish(pkgEvent('boda-dos'));

        expect(EventTemplateConfigValidator::packageFor($draft->fresh()->load('template'))?->id)->toBe($pkg['id'])
            ->and(EventTemplateConfigValidator::packageFor($published->fresh())?->id)->toBe($pkg['id']);
        $classic = Events::factory()->create(['event_type_id' => EventType::where('slug', 'wedding')->value('id')]);
        expect(EventTemplateConfigValidator::packageFor($classic))->toBeNull();
    });
});

describe('templates:lint', function () {
    it('prints the report of a good package and exits 0', function () {
        $this->artisan('templates:lint', ['path' => base_path('tests/Fixtures/TemplatePackage/good')])
            ->expectsOutputToContain('botanical 1.0.0')
            ->expectsOutputToContain('VÁLIDO')
            ->assertExitCode(0);
    });

    it('lists the errors of a bad package with code, file and line and exits 1', function () {
        $zip = PackageBuilder::good()->with('theme.css', ".pkg-a{color:red}\n.pkg-b{position:fixed}")->zip();

        $this->artisan('templates:lint', ['path' => $zip])
            ->expectsOutputToContain('[css_forbidden_property] theme.css:2')
            ->expectsOutputToContain('NO VÁLIDO')
            ->assertExitCode(1);
    });

    it('prints JSON with --json and fails for a missing path', function () {
        $zip = PackageBuilder::good()->zip();
        Artisan::call('templates:lint', ['path' => $zip, '--json' => true]);
        $out = json_decode(Artisan::output(), true);
        expect($out['valid'])->toBeTrue()->and($out)->toHaveKeys(['errors', 'warnings', 'stats', 'manifest']);

        $this->artisan('templates:lint', ['path' => '/nope.zip'])->assertExitCode(1);
    });

    it('lets the trusted console install use the engine fonts that load from Google', function () {
        $pinyon = fn () => PackageBuilder::good()->json('tokens.json', function ($t) {
            $t['fonts']['script'] = ['family' => 'Pinyon Script', 'files' => []];

            return $t;
        })->with('theme.css', '');
        $this->artisan('templates:lint', ['path' => $pinyon()->zip()])->assertExitCode(1);
        $this->artisan('templates:lint', ['path' => $pinyon()->zip(), '--allow-engine-fonts' => true])->assertExitCode(0);
    });
});

describe('wave 6 security (W6-04)', function () {
    it('refuses to publish or archive a version a package owns, so activation cannot be skipped', function () {
        pkgAdmin();
        $first = pkgInstall();
        pkgActivate($first['id']);
        // The attack of the report: a newer version of an already active template, published by hand.
        $second = pkgInstall(pkgVersion('1.1.0'));
        $draft = InvitationTemplateVersion::where('package_id', $second['id'])->where('status', 'draft')->firstOrFail();
        $liveBefore = InvitationTemplateVersion::where('template_id', $draft->template_id)->where('status', 'published')->value('id');

        $this->postJson("/api/v1/admin/templates/versions/{$draft->id}/publish", ['reason' => 'Publicar sin activar'])->assertStatus(409)->assertJson(['code' => 'package_managed']);
        $this->postJson("/api/v1/admin/templates/versions/{$draft->id}/archive", ['reason' => 'Archivar a mano'])->assertStatus(409)->assertJson(['code' => 'package_managed']);
        $live = InvitationTemplateVersion::where('package_id', $first['id'])->where('status', 'published')->firstOrFail();
        $this->postJson("/api/v1/admin/templates/versions/{$live->id}/archive", ['reason' => 'Archivar a mano'])->assertStatus(409)->assertJson(['code' => 'package_managed']);

        expect($draft->fresh()->status)->toBe('draft')
            ->and(InvitationTemplateVersion::where('template_id', $draft->template_id)->where('status', 'published')->value('id'))->toBe($liveBefore)
            ->and(TemplatePackage::find($second['id'])->status)->toBe('installed');
    });

    it('names the immutable folder with 16 hex of the content hash (W6-05)', function () {
        pkgAdmin();
        $pkg = TemplatePackage::findOrFail(pkgInstall()['id']);
        expect($pkg->storage_path)->toMatch('#^botanical/1\.0\.0-[0-9a-f]{16}$#');
    });
});

describe('wave 6 security (W6-06)', function () {
    it('answers a nesting bomb with a report, keeps the worker alive and leaves the install lock free', function () {
        pkgAdmin();
        // Random numbers between the levels keep the zip ratio under the zip-bomb check, as a real attacker would.
        mt_srand(7);
        $css = '.pkg-a{width:';
        for ($i = 0; $i < 15000; $i++) {
            $css .= 'calc(' . mt_rand(1000, 9999) . 'px+';
        }
        $css .= '1px' . str_repeat(')', 15000) . '}';
        $stage = pkgUpload(PackageBuilder::good()->with('theme.css', $css))->assertOk()->json('data');

        expect($stage['valid'])->toBeFalse()->and(array_column($stage['report']['errors'], 'code'))->toContain('css_size');
        // The next upload does not meet install_busy, and the lock a crash would leave behind lives 30 s, not 120.
        pkgUpload(PackageBuilder::good())->assertOk();
        expect(PackageStage::LOCK_SECONDS)->toBe(30);
        $lock = Cache::lock(PackageStage::LOCK, 5);
        expect($lock->get())->toBeTrue();
        $lock->release();
    });

    it('releases the install lock when the validation blows up', function () {
        pkgAdmin();
        $this->mock(PackageFiles::class, fn ($m) => $m->shouldReceive('write')->andThrow(new RuntimeException('disk full')));
        $this->withoutExceptionHandling();
        expect(fn () => pkgUpload(PackageBuilder::good()))->toThrow(RuntimeException::class);

        $lock = Cache::lock(PackageStage::LOCK, 5);
        expect($lock->get())->toBeTrue();
        $lock->release();
    });
});

describe('wave 6 security (W6-08)', function () {
    function pkgInstalledActive(): TemplatePackage
    {
        pkgAdmin();
        $pkg = pkgInstall();
        pkgActivate($pkg['id']);

        return TemplatePackage::findOrFail($pkg['id']);
    }

    it('serves the files with a fixed type, nosniff, a sandboxing CSP, CORS for the app only and immutable cache', function () {
        $pkg = pkgInstalledActive();
        config(['invitation.packages.allowed_origins' => ['https://app.quinvite.com']]);

        foreach (['theme.css' => 'text/css; charset=utf-8', 'assets/img/wash.svg' => 'image/svg+xml', 'assets/img/paper.webp' => 'image/webp', 'assets/fonts/script-400.woff2' => 'font/woff2'] as $file => $type) {
            $r = $this->get("/template-assets/{$pkg->storage_path}/{$file}", ['Origin' => 'https://app.quinvite.com'])->assertOk();
            expect($r->headers->get('Content-Type'))->toBe($type)
                ->and($r->headers->get('X-Content-Type-Options'))->toBe('nosniff')
                ->and($r->headers->get('Content-Security-Policy'))->toContain('sandbox')->toContain("default-src 'none'")
                ->and($r->headers->get('Access-Control-Allow-Origin'))->toBe('https://app.quinvite.com')
                ->and($r->headers->get('Cache-Control'))->toContain('immutable')->toContain('max-age=31536000')
                ->and($r->headers->get('Cross-Origin-Resource-Policy'))->toBe('cross-origin')
                ->and($r->headers->get('Set-Cookie'))->toBeNull();
        }
        // An origin that is not the app's gets no CORS permission.
        expect($this->get("/template-assets/{$pkg->storage_path}/theme.css", ['Origin' => 'https://evil.example'])->assertOk()->headers->get('Access-Control-Allow-Origin'))->toBeNull();
    });

    it('serves nothing outside the immutable folders of a package', function (string $path) {
        pkgInstalledActive();
        $this->get("/template-assets/{$path}")->assertNotFound();
    })->with([
        'traversal' => ['botanical/1.0.0-0123456789abcdef/assets/../../../../.env'],
        'encoded traversal' => ['botanical/1.0.0-0123456789abcdef/assets/%2e%2e/x.css'],
        'short hash' => ['botanical/1.0.0-01234567/theme.css'],
        'a stored json' => ['botanical/1.0.0-0123456789abcdef/manifest.json'],
        'not a package path' => ['../../.env'],
        'extension not served' => ['botanical/1.0.0-0123456789abcdef/assets/x.html'],
    ]);

    it('has no origin for the files outside local: the invitation falls back to Classic and the doctor complains', function () {
        $pkg = pkgInstalledActive();
        config(['filesystems.disks.template-packages.url' => null]);

        expect(app(PackagePayload::class)->forPackageId($pkg->id))->toBeNull()
            ->and(fn () => app(PackageFiles::class)->url('x/theme.css'))->toThrow(MissingPackagesUrl::class);

        app()->detectEnvironment(fn () => 'production');
        expect(ProductionSafetyCheck::problems())->toContain('TEMPLATE_PACKAGES_URL must be set (an origin without cookies, serving the headers of the spec 5.2).');
        app()->detectEnvironment(fn () => 'testing');
    });

    it('refuses to install files that differ from what was validated', function () {
        pkgAdmin();
        $stage = pkgUpload(PackageBuilder::good())->assertOk()->json('data');
        Storage::disk('template-packages')->put(PackageFiles::STAGING . "/{$stage['stageToken']}/theme.css", 'body{display:none}');

        $this->postJson(PKG, ['stage_token' => $stage['stageToken'], 'reason' => 'Instalar el paquete'])->assertStatus(409)->assertJson(['code' => 'stage_corrupt']);
        expect(TemplatePackage::count())->toBe(0)->and(Storage::disk('template-packages')->allFiles('botanical'))->toBe([]);
    });

    it('templates:verify-packages finds a tampered, a missing and an extra file', function () {
        $pkg = pkgInstalledActive();
        $disk = Storage::disk('template-packages');
        $this->artisan('templates:verify-packages')->assertSuccessful();

        $disk->put("{$pkg->storage_path}/theme.css", 'a{color:red}');
        $this->artisan('templates:verify-packages')->assertFailed();
        $disk->delete("{$pkg->storage_path}/assets/img/paper.webp");
        $disk->put("{$pkg->storage_path}/assets/img/extra.png", 'x');
        expect(Artisan::call('templates:verify-packages'))->toBe(1);
        expect(Artisan::output())->toContain('difiere theme.css')->toContain('falta assets/img/paper.webp')->toContain('sobra assets/img/extra.png');
    });

    it('templates:verify-packages --probe checks the headers of the real origin', function () {
        $pkg = pkgInstalledActive();
        config(['invitation.packages.allowed_origins' => ['https://app.quinvite.com']]);
        $good = fn (string $type) => Http::response('x', 200, [
            'Content-Type' => $type, 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'public, max-age=31536000, immutable', 'Access-Control-Allow-Origin' => 'https://app.quinvite.com',
        ]);

        $first = collect($pkg->files)->first(fn ($f) => str_starts_with($f['path'], 'assets/'))['path'];
        Http::fake(['*theme.css' => $good('text/css; charset=utf-8'), '*' . basename($first) => $good(PackageFiles::mime($first))]);
        $this->artisan('templates:verify-packages --probe')->assertSuccessful();

        Http::swap(new HttpFactory);
        Http::fake(['*' => Http::response('x', 200, ['Content-Type' => 'text/html'])]);
        expect(Artisan::call('templates:verify-packages', ['--probe' => true]))->toBe(1);
        expect(Artisan::output())->toContain('missing X-Content-Type-Options')->toContain('without sandbox');
    });
});

describe('wave 6 security (W6-13, W6-14, W6-15)', function () {
    it('W6-13 ties a stage to the admin who uploaded it: another superadmin cannot install, preview or discard it', function () {
        $a = pkgAdmin();
        $stage = pkgUpload(PackageBuilder::good())->assertOk()->json('data');
        $token = $stage['stageToken'];

        $this->actingAsReauthed(pkgStaff());
        $this->postJson(PKG, ['stage_token' => $token, 'reason' => 'Instalar lo de otra persona'])->assertForbidden()->assertJson(['code' => 'stage_forbidden']);
        $this->deleteJson(PKG . "/stages/{$token}")->assertForbidden();
        $this->postJson('/api/v1/admin/templates/preview', ['stage_token' => $token, 'eventType' => 'wedding', 'locale' => 'es'])->assertForbidden();
        expect(TemplatePackage::count())->toBe(0);

        // The owner still can.
        $this->actingAsReauthed($a);
        $this->postJson(PKG, ['stage_token' => $token, 'reason' => 'Instalar el paquete'])->assertCreated();
    });

    it('W6-13 audits the valid uploads and who made them', function () {
        $a = pkgAdmin();
        pkgUpload(PackageBuilder::good())->assertOk();

        $row = AdminAuditLog::where('action', 'template.package_validated')->firstOrFail();
        expect($row->actor_id)->toBe($a->id)
            ->and($row->after['slug'])->toBe('botanical')
            ->and($row->after['zipSha256'])->toMatch('/^[0-9a-f]{64}$/');
    });

    it('W6-14 keeps stages for 2 hours and at most 5 valid ones per admin (the upload limit is 6 a minute)', function () {
        pkgAdmin();
        expect((int) config('invitation.packages.stage_hours'))->toBe(2);
        $tokens = [];
        foreach (range(1, 6) as $i) {
            $tokens[] = pkgUpload(PackageBuilder::good()->with('theme.css', ".pkg-n{$i}{color:red}"))->assertOk()->json('data.stageToken');
        }
        $disk = Storage::disk('template-packages');
        $alive = array_filter($tokens, fn ($t) => $disk->exists(PackageFiles::STAGING . "/{$t}/theme.css"));

        expect($alive)->toHaveCount(5)->and(array_values($alive))->toBe(array_slice($tokens, 1));
        expect($this->postJson(PKG, ['stage_token' => $tokens[0], 'reason' => 'Instalar el paquete'])->status())->toBe(404);
    });

    it('W6-15 reserves the classic slug (and the engine keyframe prefixes) to the trusted console', function (string $slug) {
        pkgAdmin();
        $stage = pkgUpload(PackageBuilder::good()->json('manifest.json', fn ($m) => [...$m, 'slug' => $slug]))->assertOk()->json('data');

        expect($stage['valid'])->toBeFalse()->and(array_column($stage['report']['errors'], 'code'))->toContain('slug_invalid');
        // It must not adopt the templates of the system.
        expect(TemplatePackage::count())->toBe(0);
    })->with(['classic', 'ui', 'pk', 'inv']);

    it('W6-15 lets the trusted console lint a reserved slug only with the explicit flag', function () {
        $dir = sys_get_temp_dir() . '/pkg-classic-' . uniqid();
        mkdir($dir);
        foreach (PackageBuilder::good()->json('manifest.json', fn ($m) => [...$m, 'slug' => 'classic'])->files as $path => $bytes) {
            @mkdir(dirname("{$dir}/{$path}"), 0777, true);
            file_put_contents("{$dir}/{$path}", $bytes);
        }
        expect(Artisan::call('templates:lint', ['path' => $dir]))->toBe(1);
        expect(Artisan::call('templates:lint', ['path' => $dir, '--allow-reserved-slugs' => true]))->toBe(0);
    });
});
