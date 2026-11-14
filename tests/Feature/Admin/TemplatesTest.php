<?php

use App\Http\Services\AppServices\EventLifecycleService;
use App\Models\AdminAuditLog;
use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\Guest;
use App\Models\InvitationTemplate;
use App\Models\InvitationTemplateVersion;
use App\Models\User;
use App\Support\AdminPermissions;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\InvitationTemplateSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const TPL = '/api/v1/admin/templates';

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    User::role(AdminPermissions::STAFF_ROLES)->get()->each(fn (User $u) => $u->syncRoles([]));
});

function tplStaff(string $role = 'superadmin'): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function tplTemplate(string $slug = 'classic-quinceanera'): InvitationTemplate
{
    return InvitationTemplate::where('slug', $slug)->firstOrFail();
}

/** A draft of the template with the given changes already applied to its content. */
function tplDraft(?InvitationTemplate $template = null): int
{
    $template ??= tplTemplate();

    if ($existing = $template->versions()->where('status', 'draft')->value('id')) {
        return $existing;
    }

    return test()->actingAs(tplStaff())->postJson(TPL . "/{$template->id}/versions")->assertCreated()->json('data.id');
}

function tplPublishedEvent(string $slug, array $extra = []): Events
{
    $event = Events::factory()->draft()->create($extra + [
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', 'premium')->value('id'),
        'event_name' => 'Los XV de Valentina',
        'custom_url_slug' => $slug,
        'start_date' => now()->addMonths(3),
    ]);
    Guest::factory()->create(['event_id' => $event->id, 'code' => strtoupper(substr($slug, 0, 3)) . '123', 'parent_id' => null]);

    return $event;
}

function tplPublish(Events $event): Events
{
    return app(EventLifecycleService::class)->publish($event, null);
}

function tplContent(int $versionId): array
{
    return test()->actingAs(tplStaff())->getJson(TPL . "/versions/{$versionId}")->assertOk()->json('data');
}

/** Publishes a new version of the template whose cover title says $coverTitle. */
function tplPublishNewVersion(string $coverTitle, ?InvitationTemplate $template = null): int
{
    $draftId = tplDraft($template);
    $content = tplContent($draftId);
    $sections = $content['sections'];
    $sections[0]['title']['es'] = $coverTitle;

    test()->actingAs(tplStaff())->patchJson(TPL . "/versions/{$draftId}", ['sections' => $sections])->assertOk();
    test()->actingAs(tplStaff())->postJson(TPL . "/versions/{$draftId}/publish", ['reason' => 'Nuevo título'])->assertOk();

    return $draftId;
}

function tplCoverTitle(Events $event): string
{
    $code = Guest::where('event_id', $event->id)->value('code');

    return test()->getJson("/api/v1/app/invitation/{$event->custom_url_slug}/{$code}")->assertOk()->json('data.sections.0.title');
}

describe('permissions', function () {
    it('answers 401 to anonymous, 403 to users and support, and works for the superadmin', function () {
        $version = tplTemplate()->current_version_id;
        $id = tplTemplate()->id;
        $calls = [
            ['getJson', TPL, []],
            ['getJson', TPL . "/{$id}", []],
            ['getJson', TPL . "/versions/{$version}", []],
            ['postJson', TPL . "/{$id}/versions", []],
            ['patchJson', TPL . "/versions/{$version}", ['notes' => 'x']],
            ['postJson', TPL . "/versions/{$version}/publish", ['reason' => 'Por una razón']],
            ['postJson', TPL . "/versions/{$version}/archive", ['reason' => 'Por una razón']],
            ['postJson', TPL . '/preview', []],
        ];

        foreach ($calls as [$method, $url, $body]) {
            $this->{$method}($url, $body)->assertUnauthorized();
        }

        foreach ($calls as [$method, $url, $body]) {
            $this->actingAs(User::factory()->create())->{$method}($url, $body)->assertForbidden();
            $this->actingAs(tplStaff('support'))->{$method}($url, $body)->assertForbidden();
        }

        $this->actingAs(tplStaff())->getJson(TPL)->assertOk();
    });
});

describe('seed and data', function () {
    it('gives every template a published version 1 and the seeder never overwrites edits', function () {
        $template = tplTemplate();

        expect($template->current_version_id)->not->toBeNull()
            ->and($template->currentVersion->status)->toBe('published')
            ->and($template->currentVersion->version)->toBe(1)
            ->and($template->currentVersion->sections)->toBe($template->default_sections);

        $newId = tplPublishNewVersion('Cambiado por el admin');
        $this->seed(InvitationTemplateSeeder::class);

        expect($template->fresh()->current_version_id)->toBe($newId)
            ->and(InvitationTemplateVersion::where('template_id', $template->id)->count())->toBe(2)
            ->and($template->fresh()->currentVersion->sections[0]['title']['es'])->toBe('Cambiado por el admin');
    });

    it('backfills version 1 per template and pins only the events that are already published', function () {
        $published = tplPublishedEvent('xv-uno');
        $published->forceFill(['status' => 'published', 'published_at' => now()])->save();
        $draft = tplPublishedEvent('xv-dos');

        $migration = require database_path('migrations/2027_01_01_000002_add_versions_to_templates_and_events.php');
        $migration->down();
        DB::table('invitation_template_versions')->delete();
        $migration->up();

        $template = tplTemplate();
        expect($template->currentVersion->version)->toBe(1)
            ->and($template->currentVersion->status)->toBe('published')
            ->and($template->currentVersion->theme)->toBe($template->theme_defaults)
            ->and($published->fresh()->template_version_id)->toBe($template->current_version_id)
            ->and($draft->fresh()->template_version_id)->toBeNull();
    });
});

describe('models', function () {
    it('refuse to edit or delete what is not a draft, and allow only published to archived', function () {
        $published = tplTemplate()->currentVersion;

        expect(fn () => $published->update(['notes' => 'x']))->toThrow(LogicException::class)
            ->and(fn () => $published->update(['sections' => []]))->toThrow(LogicException::class)
            ->and(fn () => $published->delete())->toThrow(LogicException::class);

        $published = $published->fresh();
        $published->update(['status' => 'archived', 'archived_at' => now()]);
        expect($published->fresh()->status)->toBe('archived')
            ->and(fn () => $published->fresh()->update(['notes' => 'x']))->toThrow(LogicException::class)
            ->and(fn () => $published->fresh()->update(['status' => 'published']))->toThrow(LogicException::class);

        $draft = tplTemplate()->versions()->create(['version' => 2, 'status' => 'draft', 'sections' => [], 'theme' => []]);
        $draft->update(['notes' => 'ok']);
        $draft->delete();
        expect(InvitationTemplateVersion::find($draft->id))->toBeNull();
    });

    it('lets the database keep a single published and a single draft per template', function () {
        $template = tplTemplate();
        $other = fn (string $status) => fn () => $template->versions()->create(['version' => 9, 'status' => $status, 'sections' => [], 'theme' => []]);

        expect($other('published'))->toThrow(QueryException::class);
    });
});

describe('listing and detail', function () {
    it('lists templates with current version, draft and events count', function () {
        tplPublishedEvent('xv-lista');
        $draftId = tplDraft();

        $rows = $this->actingAs(tplStaff())->getJson(TPL)->assertOk()->json('data');
        $row = collect($rows)->firstWhere('slug', 'classic-quinceanera');

        expect($row)->toHaveKeys(['id', 'slug', 'name', 'eventTypeId', 'tier', 'isActive', 'currentVersion', 'draftVersionId', 'eventsCount'])
            ->and($row['currentVersion'])->toHaveKeys(['id', 'version', 'publishedAt'])
            ->and($row['currentVersion']['version'])->toBe(1)
            ->and($row['draftVersionId'])->toBe($draftId)
            ->and($row['eventsCount'])->toBe(1)
            ->and(collect($rows)->firstWhere('slug', 'classic-wedding')['draftVersionId'])->toBeNull();
    });

    it('shows a template with its versions and a version with its content', function () {
        $template = tplTemplate();
        $draftId = tplDraft();

        $detail = $this->actingAs(tplStaff())->getJson(TPL . "/{$template->id}")->assertOk()->json('data');
        expect($detail['versions'])->toHaveCount(2)
            ->and($detail['versions'][0])->toHaveKeys(['id', 'version', 'status', 'notes', 'createdAt', 'publishedAt', 'eventsCount'])
            ->and($detail['versions'][0]['id'])->toBe($draftId);

        $version = tplContent($draftId);
        expect($version)->toHaveKeys(['id', 'templateId', 'version', 'status', 'schemaVersion', 'sections', 'theme', 'notes'])
            ->and($version['status'])->toBe('draft')
            ->and($version['schemaVersion'])->toBe(1);

        $this->actingAs(tplStaff())->getJson(TPL . '/999999')->assertNotFound();
        $this->actingAs(tplStaff())->getJson(TPL . '/versions/999999')->assertNotFound();
    });

    it('does not multiply queries with the number of templates', function () {
        $admin = tplStaff();
        $count = function () use ($admin) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($admin)->getJson(TPL)->assertOk();

            return count(DB::getQueryLog());
        };

        $count(); // warm up (permission cache)
        $before = $count();

        foreach (range(1, 4) as $i) {
            $t = InvitationTemplate::create([
                'slug' => "extra-{$i}", 'name' => "Extra {$i}", 'tier' => 'free', 'default_sections' => [], 'theme_defaults' => [], 'is_active' => false,
            ]);
            $t->versions()->create(['version' => 1, 'status' => 'published', 'sections' => [], 'theme' => []]);
        }

        expect($count())->toBe($before);
    });
});

describe('drafts', function () {
    it('creates a draft copied from the published version and refuses a second one', function () {
        $template = tplTemplate();
        $admin = tplStaff();

        $res = $this->actingAs($admin)->postJson(TPL . "/{$template->id}/versions")->assertCreated()->json('data');
        expect($res['version'])->toBe(2)
            ->and($res['status'])->toBe('draft')
            ->and($res['sections'])->toBe($template->currentVersion->sections)
            ->and($res['theme'])->toBe($template->currentVersion->theme);

        $this->actingAs($admin)->postJson(TPL . "/{$template->id}/versions")
            ->assertStatus(409)->assertJson(['code' => 'draft_exists', 'draftVersionId' => $res['id']]);
    });

    it('edits only drafts: published and archived answer 409 version_immutable', function () {
        $admin = tplStaff();
        $published = tplTemplate()->current_version_id;

        $this->actingAs($admin)->patchJson(TPL . "/versions/{$published}", ['notes' => 'x'])
            ->assertStatus(409)->assertJson(['code' => 'version_immutable']);

        $draftId = tplDraft();
        $content = tplContent($draftId);
        $content['sections'][1]['title']['en'] = 'New English title';

        $res = $this->actingAs($admin)->patchJson(TPL . "/versions/{$draftId}", ['sections' => $content['sections'], 'notes' => 'Más claro'])
            ->assertOk()->json('data');
        expect($res['sections'][1]['title']['en'])->toBe('New English title')->and($res['notes'])->toBe('Más claro');

        // Publishing v2 archives v1: both are immutable from then on.
        $this->actingAs($admin)->postJson(TPL . "/versions/{$draftId}/publish", ['reason' => 'Listo para salir'])->assertOk();
        $this->actingAs($admin)->patchJson(TPL . "/versions/{$published}", ['notes' => 'x'])->assertStatus(409)->assertJson(['code' => 'version_immutable']);
        $this->actingAs($admin)->patchJson(TPL . "/versions/{$draftId}", ['notes' => 'x'])->assertStatus(409)->assertJson(['code' => 'version_immutable']);
        $this->actingAs($admin)->postJson(TPL . "/versions/{$published}/publish", ['reason' => 'Volver atrás'])->assertStatus(409)->assertJson(['code' => 'version_immutable']);
        $this->actingAs($admin)->postJson(TPL . "/versions/{$published}/archive", ['reason' => 'Ya archivada'])->assertStatus(409)->assertJson(['code' => 'version_immutable']);
    });

    it('needs something to change', function () {
        $this->actingAs(tplStaff())->patchJson(TPL . '/versions/' . tplDraft(), [])->assertUnprocessable();
    });
});

describe('publish and archive', function () {
    it('keeps a single published version, moves current_version_id and reports the events left behind', function () {
        $admin = tplStaff();
        $template = tplTemplate();
        $v1 = $template->current_version_id;
        tplPublish(tplPublishedEvent('xv-fijado'));

        $draftId = tplDraft();
        $res = $this->actingAs($admin)->postJson(TPL . "/versions/{$draftId}/publish", ['reason' => 'Nueva versión'])->assertOk()->json('data');

        expect($res['eventsOnPreviousVersion'])->toBe(1)
            ->and($res['version']['status'])->toBe('published')
            ->and($template->fresh()->current_version_id)->toBe($draftId)
            ->and(InvitationTemplateVersion::where('template_id', $template->id)->where('status', 'published')->count())->toBe(1)
            ->and(InvitationTemplateVersion::find($v1)->status)->toBe('archived')
            ->and(InvitationTemplateVersion::find($v1)->archived_at)->not->toBeNull()
            ->and(InvitationTemplateVersion::find($draftId)->published_by)->toBe($admin->id);
    });

    it('requires a reason to publish and to archive', function () {
        $draftId = tplDraft();

        $this->actingAs(tplStaff())->postJson(TPL . "/versions/{$draftId}/publish", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAs(tplStaff())->postJson(TPL . "/versions/{$draftId}/archive", ['reason' => 'no'])->assertUnprocessable()->assertJsonValidationErrors('reason');
    });

    it('archives a draft but never the only published version', function () {
        $admin = tplStaff();
        $published = tplTemplate()->current_version_id;

        $this->actingAs($admin)->postJson(TPL . "/versions/{$published}/archive", ['reason' => 'Quitarla del medio'])
            ->assertUnprocessable()->assertJson(['code' => 'cannot_archive_published']);

        $draftId = tplDraft();
        $this->actingAs($admin)->postJson(TPL . "/versions/{$draftId}/archive", ['reason' => 'Descartar borrador'])
            ->assertOk()->assertJsonPath('data.status', 'archived');

        // The slot is free again for a new draft.
        $this->actingAs($admin)->postJson(TPL . '/' . tplTemplate()->id . '/versions')->assertCreated()->assertJsonPath('data.version', 3);
    });
});

describe('events keep their look', function () {
    it('publishing v2 does not change an event pinned to v1, but changes one that is not published', function () {
        $pinned = tplPublish(tplPublishedEvent('xv-pinned'));
        $following = tplPublishedEvent('xv-following');
        $following->forceFill(['status' => 'published'])->save(); // reachable, but never went through publish(): not pinned

        expect($pinned->template_version_id)->toBe(tplTemplate()->current_version_id)
            ->and($following->fresh()->template_version_id)->toBeNull();

        tplPublishNewVersion('Título de la versión dos');

        expect(tplCoverTitle($pinned))->toBe('Te invito a celebrar')
            ->and(tplCoverTitle($following))->toBe('Título de la versión dos');

        // Publishing the follower pins it to v2 from now on, and a v3 does not move it.
        $following->forceFill(['status' => 'draft'])->save();
        tplPublish($following->fresh());
        tplPublishNewVersion('Título de la versión tres');

        expect(tplCoverTitle($following))->toBe('Título de la versión dos')
            ->and(tplCoverTitle($pinned))->toBe('Te invito a celebrar');
    });

    it('keeps the pin when the event goes back to draft and is published again', function () {
        $event = tplPublish(tplPublishedEvent('xv-vuelve'));
        $pin = $event->template_version_id;
        tplPublishNewVersion('Otra');

        app(EventLifecycleService::class)->unpublish($event, null);
        $again = tplPublish($event->fresh());

        expect($again->template_version_id)->toBe($pin);
    });

    it('serves version content from the cache by version id, without reading the version again', function () {
        $event = tplPublish(tplPublishedEvent('xv-cache'));
        tplCoverTitle($event);
        $key = "invitation.version.{$event->template_version_id}";

        expect(Cache::has($key))->toBeTrue();

        DB::flushQueryLog();
        DB::enableQueryLog();
        tplCoverTitle($event);
        $reads = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'invitation_template_versions'))->count();

        expect($reads)->toBe(0);
    });

    it('does not cache a draft', function () {
        $draftId = tplDraft();
        $event = tplPublishedEvent('xv-draft');
        $event->forceFill(['status' => 'published', 'template_version_id' => $draftId])->save();

        tplCoverTitle($event);

        expect(Cache::has("invitation.version.{$draftId}"))->toBeFalse();
    });
});

describe('validation', function () {
    function tplPatch(array $changes, ?int $draft = null): TestResponse
    {
        $draft ??= tplDraft();

        return test()->actingAs(tplStaff())->patchJson(TPL . "/versions/{$draft}", $changes);
    }

    function tplDraftContent(): array
    {
        return tplContent(tplDraft());
    }

    it('rejects a font outside the closed list, per field', function () {
        $theme = tplDraftContent()['theme'];
        $theme['fonts']['heading'] = 'Comic Sans MS';
        $theme['fonts']['script'] = 'Lora'; // a text font in the script slot

        tplPatch(['theme' => $theme])->assertUnprocessable()->assertJsonValidationErrors(['theme.fonts.heading', 'theme.fonts.script']);
    });

    it('accepts every listed font', function () {
        $theme = tplDraftContent()['theme'];
        $theme['fonts'] = ['heading' => 'Playfair Display', 'body' => 'Josefin Sans', 'script' => 'Allura'];

        tplPatch(['theme' => $theme])->assertOk();
    });

    it('accepts hex colors of 3, 4, 6 and 8 digits and rejects anything else', function () {
        $theme = tplDraftContent()['theme'];
        $theme['presets']['rose']['colors'] = ['surface' => '#fff', 'surfaceAlt' => '#ffff', 'ink' => '#112233', 'inkSoft' => '#11223344', 'accent' => '#abc', 'primary' => '#B04A62'];
        tplPatch(['theme' => $theme])->assertOk();

        foreach (['red', 'rgb(1,2,3)', '#12', '#12345', '#gggggg', 'url(x)', 'expression(alert(1))', '#fff;color:red'] as $bad) {
            $theme['presets']['rose']['colors']['primary'] = $bad;
            tplPatch(['theme' => $theme])->assertUnprocessable()->assertJsonValidationErrors(['theme.presets.rose.colors.primary']);
        }
    });

    it('requires cover and rsvp, enabled', function () {
        $sections = tplDraftContent()['sections'];
        $draft = tplDraft(tplTemplate('classic-wedding'));

        $sections[0]['enabled'] = false;
        tplPatch(['sections' => $sections], $draft)->assertUnprocessable()->assertJsonValidationErrors(['sections.0.enabled']);

        $sections[0]['enabled'] = true;
        $withoutRsvp = array_values(array_filter($sections, fn ($s) => $s['type'] !== 'rsvp'));
        tplPatch(['sections' => $withoutRsvp], $draft)->assertUnprocessable()->assertJsonValidationErrors(['sections']);

        $rsvpAt = array_search('rsvp', array_column($sections, 'type'));
        $sections[$rsvpAt]['enabled'] = false;
        tplPatch(['sections' => $sections], $draft)->assertUnprocessable()->assertJsonValidationErrors(["sections.{$rsvpAt}.enabled"]);
    });

    it('rejects external or unsafe images and accepts a relative path', function () {
        $draft = tplDraft();
        $sections = tplContent($draft)['sections'];

        foreach (['https://evil.example/x.png', '//evil.example/x.png', 'javascript:alert(1)', 'img/x.png', '/a/../../etc/passwd', '/x.png?x=1', "/x\n.png"] as $bad) {
            $sections[0]['settings']['image'] = $bad;
            tplPatch(['sections' => $sections], $draft)->assertUnprocessable()->assertJsonValidationErrors(['sections.0.settings.image']);
        }

        $sections[0]['settings']['image'] = '/assets/templates/classic-cover.jpg';
        tplPatch(['sections' => $sections], $draft)->assertOk();
    });

    it('rejects unknown section types, duplicates, HTML and long texts with field errors', function () {
        $draft = tplDraft();
        $sections = tplContent($draft)['sections'];

        $sections[1]['type'] = 'gallery';
        $sections[2]['title']['es'] = '<script>alert(1)</script>';
        $sections[3]['text']['en'] = str_repeat('a', 1001);
        $sections[4]['type'] = 'cover';
        $sections[5]['title'] = ['es' => 'Hola', 'fr' => 'Salut'];

        tplPatch(['sections' => $sections], $draft)->assertUnprocessable()->assertJsonValidationErrors([
            'sections.1.type', 'sections.2.title.es', 'sections.3.text.en', 'sections.4.type', 'sections.5.title.fr',
        ]);
    });

    it('accepts plain text with a lone less-than sign', function () {
        $draft = tplDraft();
        $sections = tplContent($draft)['sections'];
        $sections[1]['text']['es'] = 'Menos de 5 < 10 invitados & más';

        tplPatch(['sections' => $sections], $draft)->assertOk();
    });

    it('limits the number of sections, presets and the size of the document', function () {
        $draft = tplDraft();
        $content = tplContent($draft);

        $many = array_map(fn ($i) => $content['sections'][0], range(1, 21));
        tplPatch(['sections' => $many], $draft)->assertUnprocessable()->assertJsonValidationErrors(['sections']);

        $theme = $content['theme'];
        foreach (range(1, 9) as $i) {
            $theme['presets']["p{$i}"] = $theme['presets']['rose'];
        }
        tplPatch(['theme' => $theme], $draft)->assertUnprocessable()->assertJsonValidationErrors(['theme.presets']);

        // 70 KB of notes-like content spread in texts (each under 1000): the JSON limit is what stops it.
        $huge = $content['sections'];
        foreach (range(1, 80) as $i) {
            $huge[0]['title']['x' . $i] = str_repeat('a', 900);
        }
        tplPatch(['sections' => $huge], $draft)->assertUnprocessable()->assertJsonValidationErrors(['sections']);
        expect(tplContent($draft)['sections'])->toBe($content['sections']); // nothing was saved
    });

    it('requires the active preset to exist and the theme to be known', function () {
        $theme = tplDraftContent()['theme'];
        $theme['preset'] = 'ghost';
        $theme['theme'] = 'neon';

        tplPatch(['theme' => $theme])->assertUnprocessable()->assertJsonValidationErrors(['theme.preset', 'theme.theme']);
    });
});

describe('audit', function () {
    it('records created, updated, published and archived with the reason where it applies', function () {
        $admin = tplStaff();
        $template = tplTemplate();

        $draft = $this->actingAs($admin)->postJson(TPL . "/{$template->id}/versions")->json('data.id');
        $this->actingAs($admin)->patchJson(TPL . "/versions/{$draft}", ['notes' => 'Ajuste'])->assertOk();
        $this->actingAs($admin)->postJson(TPL . "/versions/{$draft}/publish", ['reason' => 'Sale a producción'])->assertOk();
        $second = $this->actingAs($admin)->postJson(TPL . "/{$template->id}/versions")->json('data.id');
        $this->actingAs($admin)->postJson(TPL . "/versions/{$second}/archive", ['reason' => 'No hace falta'])->assertOk();

        $log = AdminAuditLog::where('subject_type', 'template_version')->orderBy('id')->get();

        expect($log->pluck('action')->all())->toBe([
            'template.version_created', 'template.version_updated', 'template.version_published',
            'template.version_created', 'template.version_archived',
        ])->and($log->pluck('actor_id')->unique()->all())->toBe([$admin->id])
            ->and($log[2]->reason)->toBe('Sale a producción')
            ->and($log[2]->after['eventsOnPreviousVersion'])->toBe(0)
            ->and($log[4]->reason)->toBe('No hace falta')
            ->and($log[1]->after['fields'])->toBe(['notes']);
    });

    it('does not record reads, previews or failed attempts', function () {
        $admin = tplStaff();
        $published = tplTemplate()->current_version_id;
        $before = AdminAuditLog::count();

        $this->actingAs($admin)->getJson(TPL)->assertOk();
        $this->actingAs($admin)->patchJson(TPL . "/versions/{$published}", ['notes' => 'x'])->assertStatus(409);
        $this->actingAs($admin)->postJson(TPL . "/versions/{$published}/archive", ['reason' => 'Intento fallido'])->assertUnprocessable();

        expect(AdminAuditLog::count())->toBe($before);
    });
});

describe('preview', function () {
    it('resolves the payload with sample data in the shape of the public invitation', function () {
        tplPublishedEvent('xv-forma');
        $content = tplContent(tplTemplate()->current_version_id);

        $preview = $this->actingAs(tplStaff())->postJson(TPL . '/preview', [
            'sections' => $content['sections'], 'theme' => $content['theme'], 'locale' => 'en', 'eventType' => 'wedding',
        ])->assertOk()->json('data');

        $public = tplCoverPayload();

        expect(array_keys($preview))->toBe(array_keys($public))
            ->and($preview['locale'])->toBe('en')
            ->and($preview['event']['typeSlug'])->toBe('wedding')
            ->and($preview['sections'][0]['type'])->toBe('cover')
            ->and($preview['sections'][0]['title'])->toBe('Join me to celebrate')
            ->and(collect($preview['sections'])->pluck('type')->all())->toBe(array_column(array_filter($content['sections'], fn ($s) => $s['enabled']), 'type'))
            ->and(array_keys($preview['sections'][0]))->toBe(array_keys($public['sections'][0]))
            ->and($preview['theme']['colors'])->toHaveKey('primary');

        // Sample data in code: no real event or guest leaks in.
        expect(json_encode($preview))->not->toContain('xv-forma');
    });

    it('honors enabled flags and order, and validates the content like a save', function () {
        $content = tplContent(tplTemplate()->current_version_id);
        $sections = $content['sections'];
        $closing = array_search('closing', array_column($sections, 'type'));
        $sections[$closing]['enabled'] = false;
        $sections[0]['order'] = 50;

        $types = $this->actingAs(tplStaff())->postJson(TPL . '/preview', [
            'sections' => $sections, 'theme' => $content['theme'], 'locale' => 'es', 'eventType' => 'quinceanera',
        ])->assertOk()->json('data.sections.*.type');

        expect($types)->not->toContain('closing')->and(end($types))->toBe('cover');

        $sections[0]['settings']['image'] = 'https://evil.example/a.png';
        $this->actingAs(tplStaff())->postJson(TPL . '/preview', [
            'sections' => $sections, 'theme' => $content['theme'], 'locale' => 'es', 'eventType' => 'quinceanera',
        ])->assertUnprocessable()->assertJsonValidationErrors(['sections.0.settings.image']);

        $this->actingAs(tplStaff())->postJson(TPL . '/preview', [
            'sections' => $sections, 'theme' => $content['theme'], 'locale' => 'fr', 'eventType' => 'party',
        ])->assertUnprocessable()->assertJsonValidationErrors(['locale', 'eventType']);
    });

    function tplCoverPayload(): array
    {
        $event = tplPublish(tplPublishedEvent('xv-publico'));
        $code = Guest::where('event_id', $event->id)->value('code');

        return test()->getJson("/api/v1/app/invitation/xv-publico/{$code}")->assertOk()->json('data');
    }
});
