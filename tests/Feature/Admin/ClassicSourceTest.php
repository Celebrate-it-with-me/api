<?php

use App\Http\Services\Admin\AdminAudit;
use App\Http\Services\Admin\Templates\Packages\ClassicSource;
use App\Http\Services\Admin\Templates\Packages\PackageStage;
use App\Http\Services\AppServices\InvitationService;
use App\Http\Services\AppServices\ModuleSupport;
use App\Models\AdminAuditLog;
use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\Guest;
use App\Models\InvitationTemplate;
use App\Models\InvitationTemplateVersion as Version;
use App\Models\TemplatePackage;
use App\Models\User;
use App\Support\AdminPermissions;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Unit\TemplatePackage\PackageBuilder;

/**
 * C2 of docs/plans/free-cover-composition-contract.md: the reserved `classic` package, installed only from the trusted
 * console, and the switch (`templates:classic-source`) that decides where the free Classic is drawn from.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    Storage::fake('template-packages');
    Storage::fake('local');
    config(['invitation.engine' => 1]);
});

afterEach(fn () => PackageBuilder::cleanup());

/** The good package dressed as the reserved Classic: free, both event types, and the three governed modules. */
function clsPackage(string $version = '1.0.0', bool $withModules = true): PackageBuilder
{
    $b = PackageBuilder::good()->json('manifest.json', fn ($m) => [...$m, 'slug' => 'classic', 'version' => $version, 'tier' => 'free']);
    if (! $withModules) {
        return $b;
    }

    return $b
        ->json('sections.json', function (array $lists) {
            foreach ($lists as $event => $sections) {
                foreach ([['tables', 10], ['comments', 11], ['suggested_music', 12]] as [$type, $order]) {
                    $sections[] = ['type' => $type, 'enabled' => false, 'order' => $order, 'title' => ['es' => 'Sección', 'en' => 'Section'], 'text' => ['es' => null, 'en' => null], 'settings' => (object) []];
                }
                $lists[$event] = $sections;
            }

            return $lists;
        })
        ->with('sections/tables.html', '<div class="pkg-t"><q-tables variant="card"></q-tables></div>')
        ->with('sections/comments.html', '<div class="pkg-c"><q-comments variant="cards" max="5"></q-comments></div>')
        ->with('sections/suggested_music.html', '<div class="pkg-m"><q-suggested-music variant="cards"></q-suggested-music></div>');
}

/** @return int the exit code, with the output left in Artisan::output() */
function clsInstall(PackageBuilder $b, string $reason = 'Instalar la Clásica como paquete'): int
{
    return Artisan::call('templates:install-classic', ['path' => $b->zip(), '--reason' => $reason]);
}

function clsSource(string $mode, string $reason = 'Cambiar el origen de la Clásica'): int
{
    return Artisan::call('templates:classic-source', ['mode' => $mode, '--reason' => $reason]);
}

function clsTemplate(string $type): InvitationTemplate
{
    return InvitationTemplate::where('slug', "classic-{$type}")->firstOrFail();
}

/** An event of the given type that draws the Classic template and has a guest with the code PKG123. */
function clsEvent(string $slug, string $type = 'wedding', bool $published = true): Events
{
    $event = Events::factory()->draft()->create([
        'event_type_id' => EventType::where('slug', $type)->value('id'),
        'event_plan_id' => EventPlan::where('slug', 'premium')->value('id'),
        'event_name' => 'Boda de Camila y Andrés',
        'custom_url_slug' => $slug,
        'start_date' => now()->addMonths(3),
        'template_id' => clsTemplate($type)->id,
    ]);
    Guest::factory()->create(['event_id' => $event->id, 'code' => 'PKG123', 'parent_id' => null]);
    if ($published) {
        $event->forceFill(['status' => 'published', 'published_at' => now()])->save();
    }

    return $event;
}

function clsPayload(string $slug): array
{
    return test()->getJson("/api/v1/app/invitation/{$slug}/PKG123")->assertOk()->json('data');
}

describe('install', function () {
    it('installs the reserved classic package from the console as new, immutable versions and leaves the public untouched', function () {
        $native = [clsTemplate('quinceanera')->current_version_id, clsTemplate('wedding')->current_version_id];
        $nativeRows = Version::whereIn('id', $native)->get()->map->only(['id', 'status', 'package_id', 'sections', 'theme'])->all();

        expect(clsInstall(clsPackage()))->toBe(0)->and(Artisan::output())->toContain('Instalado: classic 1.0.0')->toContain('Origen de la Clásica ahora: native');

        $pkg = TemplatePackage::where('slug', 'classic')->sole();
        expect($pkg->status)->toBe('active')->and($pkg->tier)->toBe('free')->and($pkg->installed_by)->toBeNull();
        // The native versions are exactly as they were, and the pointer did not move.
        expect(Version::whereIn('id', $native)->get()->map->only(['id', 'status', 'package_id', 'sections', 'theme'])->all())->toBe($nativeRows)
            ->and([clsTemplate('quinceanera')->current_version_id, clsTemplate('wedding')->current_version_id])->toBe($native)
            ->and(clsTemplate('wedding')->is_active)->toBeTrue();
        // One new version per event type, owned by the package, parked (never draft, never the one published index).
        $versions = Version::where('package_id', $pkg->id)->get();
        expect($versions)->toHaveCount(2)->and($versions->pluck('status')->unique()->all())->toBe(['archived'])
            ->and($versions->pluck('template_id')->sort()->values()->all())->toBe(collect([clsTemplate('quinceanera')->id, clsTemplate('wedding')->id])->sort()->values()->all());
        expect(AdminAuditLog::where('action', 'template.classic_package_installed')->sole()->reason)->toBe('Instalar la Clásica como paquete');
    });

    it('brings comments, tables and suggested music: no missing modules, and it refuses the switch if one is missing', function () {
        clsInstall(clsPackage());
        expect(ModuleSupport::missingFor(TemplatePackage::where('slug', 'classic')->sole()))->toBe([])
            ->and(Artisan::output())->not->toContain('faltan módulos');
    });

    it('warns when a module block is missing and does not let the switch go to package', function () {
        clsInstall(clsPackage(withModules: false));
        expect(Artisan::output())->toContain('Al paquete le faltan módulos');
        $before = clsTemplate('wedding')->current_version_id;

        expect(clsSource('package'))->toBe(1)->and(Artisan::output())->toContain('dejarían de poder activarlos')
            ->and(clsTemplate('wedding')->current_version_id)->toBe($before);
    });

    it('is idempotent: the same version with the same content changes nothing', function () {
        clsInstall(clsPackage());
        $state = [TemplatePackage::count(), Version::count(), AdminAuditLog::where('action', 'template.classic_package_installed')->count()];

        expect(clsInstall(clsPackage()))->toBe(0)->and(Artisan::output())->toContain('Ya estaba instalado, sin cambios');
        expect([TemplatePackage::count(), Version::count(), AdminAuditLog::where('action', 'template.classic_package_installed')->count()])->toBe($state);
        // No staging leftovers either.
        expect(Storage::disk('local')->files('template-staging'))->toBe([]);
    });

    it('finishes a half installation when it is run again (drafts left by a crash)', function () {
        clsInstall(clsPackage());
        $pkg = TemplatePackage::where('slug', 'classic')->sole();
        // Simulate the crash: the install happened, the parking did not.
        Version::where('package_id', $pkg->id)->update(['status' => 'draft', 'archived_at' => null]);
        $pkg->forceFill(['status' => 'installed', 'activated_at' => null])->save();

        expect(clsInstall(clsPackage()))->toBe(0);
        expect(Version::where('package_id', $pkg->id)->pluck('status')->unique()->all())->toBe(['archived'])->and($pkg->fresh()->status)->toBe('active');
    });

    it('refuses the same version with other content', function () {
        clsInstall(clsPackage());

        expect(clsInstall(clsPackage()->with('theme.css', '.pkg-other{color:red}')))->toBe(1)->and(Artisan::output())->toContain('ya está instalada con otro contenido');
        expect(TemplatePackage::count())->toBe(1);
    });

    it('installs a newer version as new versions, again without moving the pointer', function () {
        clsInstall(clsPackage());
        $pointer = clsTemplate('wedding')->current_version_id;

        expect(clsInstall(clsPackage('1.1.0')))->toBe(0);
        expect(TemplatePackage::where('slug', 'classic')->count())->toBe(2)->and(Version::whereNotNull('package_id')->count())->toBe(4)
            ->and(clsTemplate('wedding')->current_version_id)->toBe($pointer);
    });

    it('refuses an invalid package, a package that is not classic, a missing path and a missing reason', function () {
        expect(clsInstall(clsPackage()->without('LICENSES.md')))->toBe(1)->and(Artisan::output())->toContain('El paquete no es válido');
        expect(clsInstall(PackageBuilder::good()))->toBe(1)->and(Artisan::output())->toContain('solo instala el paquete «classic»');
        expect(Artisan::call('templates:install-classic', ['path' => '/nope/classic.zip', '--reason' => 'Instalar la Clásica']))->toBe(1);
        expect(Artisan::call('templates:install-classic', ['path' => clsPackage()->zip(), '--reason' => 'no']))->toBe(2);
        expect(Artisan::call('templates:install-classic', ['path' => clsPackage()->zip()]))->toBe(2);
        expect(TemplatePackage::count())->toBe(0)->and(Version::whereNotNull('package_id')->count())->toBe(0)
            ->and(Storage::disk('local')->files('template-staging'))->toBe([]);
    });

    it('reads the package from a folder and leaves the dev-only package.json and ast.json out', function () {
        $dir = sys_get_temp_dir() . '/cls-' . uniqid();
        foreach (clsPackage()->with('package.json', '{}')->with('ast.json', '{}')->files as $path => $bytes) {
            @mkdir(dirname("{$dir}/{$path}"), 0777, true);
            file_put_contents("{$dir}/{$path}", $bytes);
        }

        expect(Artisan::call('templates:install-classic', ['path' => $dir, '--reason' => 'Instalar desde carpeta']))->toBe(0);
        expect(TemplatePackage::where('slug', 'classic')->exists())->toBeTrue();
    });

    it('is out of reach of the admin web: a classic zip is refused by the upload, and there is no route that moves the switch', function () {
        $admin = User::factory()->create();
        $admin->assignRole(AdminPermissions::STAFF_ROLES[0] ?? 'superadmin');
        $this->actingAsReauthed($admin);

        $stage = $this->post('/api/v1/admin/templates/packages/validate', ['package' => UploadedFile::fake()->createWithContent('classic.zip', (string) file_get_contents(clsPackage()->zip()))], ['Accept' => 'application/json']);
        $stage->assertOk();
        expect($stage->json('data.valid'))->toBeFalse()->and(array_column($stage->json('data.report.errors'), 'code'))->toContain('slug_invalid');
        // Not valid, so there is nothing to install: a made-up token cannot install it either.
        $this->postJson('/api/v1/admin/templates/packages', ['stage_token' => $stage->json('data.stageToken'), 'reason' => 'Instalar la Clásica'])->assertStatus(422);
        expect(TemplatePackage::count())->toBe(0);

        $routes = collect(app('router')->getRoutes()->getRoutes())->map->uri()->filter(fn ($u) => str_contains($u, 'classic'))->all();
        expect($routes)->toBe([]);
    });

    it('does not mark the console as trusted in the shared validation context', function () {
        $context = app(PackageStage::class)->context();
        expect($context->allowReservedSlugs)->toBeFalse()->and($context->allowEngineFonts)->toBeFalse();
        clsInstall(clsPackage());
        expect(app(PackageStage::class)->context()->allowReservedSlugs)->toBeFalse();
    });
});

describe('the lifecycle endpoints of the admin never touch the reserved package (CS-001, CS-002)', function () {
    function clsStaff(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');
        test()->actingAsReauthed($admin);

        return $admin;
    }

    it('answers 409 console_only to activate, deactivate and withdraw, and changes nothing', function () {
        clsInstall(clsPackage());
        clsStaff();
        $pkg = TemplatePackage::where('slug', 'classic')->sole();
        $state = fn () => [(array) DB::table('template_packages')->where('id', $pkg->id)->first(['status', 'activated_at', 'deactivated_at', 'withdrawn_at']), Version::orderBy('id')->get()->map->only(['id', 'status'])->all(), InvitationTemplate::orderBy('id')->get()->map->only(['id', 'is_active', 'current_version_id'])->all()];
        $before = $state();
        $base = '/api/v1/admin/templates/packages/' . $pkg->id;

        $this->postJson("{$base}/activate", ['reason' => 'Intentar activar', 'checklist' => ['accessibility' => true, 'contrast' => true, 'long_names' => true, 'no_photo' => true, 'sections_reordered' => true, 'performance' => true]])->assertStatus(409)->assertJson(['code' => 'console_only']);
        $this->postJson("{$base}/deactivate", ['reason' => 'Intentar desactivar'])->assertStatus(409)->assertJson(['code' => 'console_only']);
        $this->deleteJson($base, ['reason' => 'Intentar retirar'])->assertStatus(409)->assertJson(['code' => 'console_only']);

        expect($state())->toBe($before)->and(clsTemplate('wedding')->is_active)->toBeTrue();
        expect(AdminAuditLog::whereIn('action', ['template.package_activated', 'template.package_deactivated', 'template.package_withdrawn'])->count())->toBe(0);
    });

    it('cannot activate a half installed classic: the native version stays published', function () {
        clsInstall(clsPackage());
        $pkg = TemplatePackage::where('slug', 'classic')->sole();
        // The state an old crash could leave: installed, with drafts.
        Version::where('package_id', $pkg->id)->update(['status' => 'draft', 'archived_at' => null]);
        $pkg->forceFill(['status' => 'installed', 'activated_at' => null])->save();
        $native = clsTemplate('wedding')->current_version_id;
        clsStaff();

        $this->postJson('/api/v1/admin/templates/packages/' . $pkg->id . '/activate', ['reason' => 'Activar a medias', 'checklist' => ['accessibility' => true, 'contrast' => true, 'long_names' => true, 'no_photo' => true, 'sections_reordered' => true, 'performance' => true]])->assertStatus(409)->assertJson(['code' => 'console_only']);
        expect(Version::find($native)->status)->toBe('published')->and(clsTemplate('wedding')->current_version_id)->toBe($native);
    });

    it('still lets the admin manage the other packages', function () {
        clsStaff();
        $up = $this->post('/api/v1/admin/templates/packages/validate', ['package' => UploadedFile::fake()->createWithContent('b.zip', (string) file_get_contents(PackageBuilder::good()->zip()))], ['Accept' => 'application/json'])->assertOk()->json('data');
        $id = $this->postJson('/api/v1/admin/templates/packages', ['stage_token' => $up['stageToken'], 'reason' => 'Instalar botanica'])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/admin/templates/packages/{$id}/activate", ['reason' => 'Activar botanica', 'checklist' => ['accessibility' => true, 'contrast' => true, 'long_names' => true, 'no_photo' => true, 'sections_reordered' => true, 'performance' => true]])->assertOk();
    });
});

describe('the install is atomic (CS-001)', function () {
    it('leaves nothing behind when the parking or its audit fails', function () {
        $this->app->bind(AdminAudit::class, fn ($app) => new class($app['request']) extends AdminAudit
        {
            public function record(string $action, $subject = null, array $data = []): AdminAuditLog
            {
                if ($action === 'template.classic_package_installed') {
                    throw new RuntimeException('boom');
                }

                return parent::record($action, $subject, $data);
            }
        });
        $before = [TemplatePackage::count(), Version::count()];

        expect(clsInstall(clsPackage()))->toBe(1)->and(Artisan::output())->toContain('boom');

        expect([TemplatePackage::count(), Version::count()])->toBe($before)
            ->and(Storage::disk('template-packages')->allFiles('classic'))->toBe([])
            ->and(AdminAuditLog::where('action', 'template.classic_package_installed')->count())->toBe(0);
    });

    it('never has the package installed with drafts: it is parked in the same transaction', function () {
        clsInstall(clsPackage());
        expect(Version::whereNotNull('package_id')->where('status', 'draft')->count())->toBe(0)
            ->and(TemplatePackage::where('slug', 'classic')->value('status'))->toBe('active');
    });
});

describe('the reason and the audit (CS-003)', function () {
    it('refuses a reason of 501 characters before writing anything, in both commands and in the service', function () {
        $long = str_repeat('a', 501);
        expect(Artisan::call('templates:install-classic', ['path' => clsPackage()->zip(), '--reason' => $long]))->toBe(2)
            ->and(Artisan::output())->toContain('entre 5 y 500');
        expect(TemplatePackage::count())->toBe(0)->and(Version::whereNotNull('package_id')->count())->toBe(0)->and(Storage::disk('local')->files('template-staging'))->toBe([]);

        clsInstall(clsPackage());
        $pointers = [clsTemplate('quinceanera')->current_version_id, clsTemplate('wedding')->current_version_id];
        expect(clsSource('package', $long))->toBe(2)->and(Artisan::output())->toContain('entre 5 y 500');
        expect([clsTemplate('quinceanera')->current_version_id, clsTemplate('wedding')->current_version_id])->toBe($pointers)
            ->and(AdminAuditLog::where('action', 'template.classic_source_changed')->count())->toBe(0);

        expect(fn () => app(ClassicSource::class)->switchTo('package', $long))->toThrow(InvalidArgumentException::class)
            ->and(fn () => app(ClassicSource::class)->install(clsPackage()->zip(), $long))->toThrow(InvalidArgumentException::class);
    });

    it('accepts a reason of exactly 500 characters and keeps it whole in the audit log', function () {
        $limit = str_repeat('é', 500);
        expect(clsInstall(clsPackage(), $limit))->toBe(0)->and(clsSource('package', $limit))->toBe(0);
        expect(AdminAuditLog::where('action', 'template.classic_source_changed')->sole()->reason)->toBe($limit)
            ->and(AdminAuditLog::where('action', 'template.classic_package_installed')->sole()->reason)->toBe($limit);
    });

    it('moves nothing when the audit of the switch cannot be written', function () {
        clsInstall(clsPackage());
        $pointers = [clsTemplate('quinceanera')->current_version_id, clsTemplate('wedding')->current_version_id];
        $this->app->bind(AdminAudit::class, fn ($app) => new class($app['request']) extends AdminAudit
        {
            public function record(string $action, $subject = null, array $data = []): AdminAuditLog
            {
                throw new RuntimeException('audit down');
            }
        });

        expect(fn () => app(ClassicSource::class)->switchTo('package', 'Cambiar el origen'))->toThrow(RuntimeException::class, 'audit down');
        expect([clsTemplate('quinceanera')->current_version_id, clsTemplate('wedding')->current_version_id])->toBe($pointers);
    });
});

describe('publishing a native Classic version while the source is the package (CS-005)', function () {
    function clsNativeDraft(string $type): Version
    {
        $native = Version::where('template_id', clsTemplate($type)->id)->whereNull('package_id')->where('status', 'published')->firstOrFail();

        return Version::create(['template_id' => $native->template_id, 'version' => (int) Version::where('template_id', $native->template_id)->max('version') + 1, 'status' => 'draft', 'sections' => $native->sections, 'theme' => $native->theme, 'schema_version' => $native->schema_version]);
    }

    it('is refused with 409 classic_source_package and moves nothing, so the source can never turn mixed in silence', function () {
        clsInstall(clsPackage());
        clsSource('package');
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');
        $this->actingAsReauthed($admin);
        $draft = clsNativeDraft('wedding');
        $pointers = [clsTemplate('quinceanera')->current_version_id, clsTemplate('wedding')->current_version_id];

        $this->postJson("/api/v1/admin/templates/versions/{$draft->id}/publish", ['reason' => 'Publicar nativa nueva'])->assertStatus(409)->assertJson(['code' => 'classic_source_package']);

        expect([clsTemplate('quinceanera')->current_version_id, clsTemplate('wedding')->current_version_id])->toBe($pointers)
            ->and($draft->fresh()->status)->toBe('draft')
            ->and(app(ClassicSource::class)->current())->toBe('package')
            ->and(AdminAuditLog::where('action', 'template.version_published')->count())->toBe(0);
    });

    it('is allowed once the source is native again, and it does not touch the other templates', function () {
        clsInstall(clsPackage());
        clsSource('package');
        clsSource('native');
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');
        $this->actingAsReauthed($admin);
        $draft = clsNativeDraft('wedding');

        $this->postJson("/api/v1/admin/templates/versions/{$draft->id}/publish", ['reason' => 'Publicar nativa nueva'])->assertOk();
        expect(clsTemplate('wedding')->current_version_id)->toBe($draft->id)->and(app(ClassicSource::class)->current())->toBe('native');
    });
});

describe('the switch', function () {
    it('is native by default, and an unflipped install changes what the public sees in nothing', function () {
        $event = clsEvent('boda-camila');
        $before = clsPayload('boda-camila');
        expect(clsSource('status'))->toBe(0)->and(Artisan::output())->toContain('classic_source = native');

        clsInstall(clsPackage());
        Cache::flush();
        expect(clsSource('status'))->toBe(0)->and(Artisan::output())->toContain('classic_source = native')
            ->and(clsPayload('boda-camila'))->toBe($before)
            ->and($before['theme']['name'])->toBe('classic')->and($before['theme'])->not->toHaveKey('package');
    });

    it('points events without a fixed version to the package, keeps the fixed ones, and goes back', function () {
        $pinned = clsEvent('boda-fija');
        app(InvitationService::class)->pinVersion($pinned);
        $pinned->save();
        $pinnedVersion = $pinned->fresh()->template_version_id;
        $free = clsEvent('boda-libre');
        expect($free->fresh()->template_version_id)->toBeNull();
        $quince = clsEvent('quince-libre', 'quinceanera');
        clsInstall(clsPackage());
        $nativeBefore = [clsTemplate('quinceanera')->current_version_id, clsTemplate('wedding')->current_version_id];

        expect(clsSource('package'))->toBe(0)->and(Artisan::output())->toContain('native -> package');
        foreach (['wedding', 'quinceanera'] as $type) {
            $current = clsTemplate($type)->currentVersion;
            expect($current->package_id)->not->toBeNull()->and($current->package->slug)->toBe('classic');
        }
        expect(clsPayload('boda-libre')['theme']['package']['slug'])->toBe('classic')
            ->and(clsPayload('quince-libre')['theme']['package']['slug'])->toBe('classic')
            // The event published before the switch keeps the native Classic and the very same version.
            ->and(clsPayload('boda-fija')['theme'])->not->toHaveKey('package')
            ->and($pinned->fresh()->template_version_id)->toBe($pinnedVersion);

        // Back: nothing to migrate, the native versions are the very same rows.
        expect(clsSource('native'))->toBe(0)->and(Artisan::output())->toContain('package -> native');
        expect([clsTemplate('quinceanera')->current_version_id, clsTemplate('wedding')->current_version_id])->toBe($nativeBefore)
            ->and(clsPayload('boda-libre')['theme'])->not->toHaveKey('package')
            ->and(clsPayload('boda-fija')['theme'])->not->toHaveKey('package');
        expect(Version::whereIn('id', $nativeBefore)->pluck('status')->unique()->all())->toBe(['published']);
    });

    it('drops the cached invitation when the source changes (no stale payload for a guest)', function () {
        clsEvent('boda-libre');
        clsInstall(clsPackage());
        // Warm every cache with the native one.
        expect(clsPayload('boda-libre')['theme'])->not->toHaveKey('package');

        clsSource('package');
        expect(clsPayload('boda-libre')['theme']['package']['slug'])->toBe('classic');
        clsSource('native');
        expect(clsPayload('boda-libre')['theme'])->not->toHaveKey('package');
    });

    it('lets the event publish after the switch fix the package version, and keeps it when the switch goes back', function () {
        clsInstall(clsPackage());
        clsSource('package');
        $event = clsEvent('boda-nueva', 'wedding', false);
        app(InvitationService::class)->pinVersion($event);
        $event->forceFill(['status' => 'published', 'published_at' => now()])->save();
        $fixed = $event->fresh()->template_version_id;
        expect(Version::find($fixed)->package_id)->not->toBeNull();

        clsSource('native');
        expect($event->fresh()->template_version_id)->toBe($fixed)->and(clsPayload('boda-nueva')['theme']['package']['slug'])->toBe('classic');
    });

    it('does not change a thing when it is already there, and audits only real changes', function () {
        clsInstall(clsPackage());

        expect(clsSource('native'))->toBe(0)->and(Artisan::output())->toContain('sin cambios');
        expect(AdminAuditLog::where('action', 'template.classic_source_changed')->count())->toBe(0);

        clsSource('package');
        expect(clsSource('package'))->toBe(0)->and(Artisan::output())->toContain('sin cambios');
        $log = AdminAuditLog::where('action', 'template.classic_source_changed')->sole();
        expect($log->reason)->toBe('Cambiar el origen de la Clásica')->and($log->before['source'])->toBe('native')->and($log->after['source'])->toBe('package')
            ->and(array_keys($log->after['currentVersionIds']))->toBe(['classic-quinceanera', 'classic-wedding']);
    });

    it('is all or nothing: if one template has no package version, none moves', function () {
        clsInstall(clsPackage());
        $pkg = TemplatePackage::where('slug', 'classic')->sole();
        DB::table('invitation_template_versions')->where('package_id', $pkg->id)->where('template_id', clsTemplate('wedding')->id)->delete();
        $before = [clsTemplate('quinceanera')->current_version_id, clsTemplate('wedding')->current_version_id];

        expect(clsSource('package'))->toBe(1)->and(Artisan::output())->toContain('classic-wedding no tiene una versión del paquete');
        expect([clsTemplate('quinceanera')->current_version_id, clsTemplate('wedding')->current_version_id])->toBe($before);
    });

    it('refuses to go native when there is no published native version, and never uses a withdrawn package', function () {
        clsInstall(clsPackage());
        clsSource('package');
        DB::table('invitation_template_versions')->where('template_id', clsTemplate('wedding')->id)->whereNull('package_id')->update(['status' => 'archived']);
        expect(clsSource('native'))->toBe(1)->and(Artisan::output())->toContain('no tiene una versión nativa publicada');
        expect(clsTemplate('wedding')->currentVersion->package_id)->not->toBeNull();

        DB::table('invitation_template_versions')->where('template_id', clsTemplate('wedding')->id)->whereNull('package_id')->update(['status' => 'published']);
        clsSource('native');
        TemplatePackage::where('slug', 'classic')->update(['status' => 'withdrawn']);
        expect(clsSource('package'))->toBe(1)->and(Artisan::output())->toContain('no tiene una versión del paquete Clásica instalada');
    });

    it('reports a mixed or unknown state and refuses bad arguments', function () {
        clsInstall(clsPackage());
        clsSource('package');
        $wedding = clsTemplate('wedding');
        $wedding->forceFill(['current_version_id' => Version::where('template_id', $wedding->id)->whereNull('package_id')->value('id')])->save();
        expect(app(ClassicSource::class)->current())->toBe('mixed');

        expect(clsSource('sideways'))->toBe(2)->and(clsSource('package', 'no'))->toBe(2);
        expect(fn () => app(ClassicSource::class)->switchTo('x', 'Una razón válida'))->toThrow(InvalidArgumentException::class);

        InvitationTemplate::where('slug', 'classic-wedding')->update(['slug' => 'classic-wedding-x']);
        expect(app(ClassicSource::class)->current())->toBe('unknown')->and(clsSource('package'))->toBe(1)->and(Artisan::output())->toContain('Faltan las plantillas');
    });
});
