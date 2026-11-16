<?php

use App\Http\Services\AppServices\Invitation\CoverPhoto;
use App\Models\EventActivity;
use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\EventUserRole;
use App\Models\Guest;
use App\Models\InvitationTemplate;
use App\Models\User;
use App\Support\AdminPermissions;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
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

/** A package of another author than the sample botanical one: free, with presets, a text, a toggle and a paid color. */
function edBuilder(string $slug = 'festival', string $tier = 'free'): PackageBuilder
{
    return PackageBuilder::good()
        ->json('manifest.json', fn (array $m) => [...$m, 'slug' => $slug, 'tier' => $tier, 'name' => ['es' => 'Fiesta ' . $slug, 'en' => 'Feast ' . $slug]])
        ->json('tokens.json', function (array $t) {
            $salvia = $t['presets']['salvia'];
            $t['presets']['oliva'] = ['label' => ['es' => 'Oliva', 'en' => 'Olive'], 'colors' => [...$salvia['colors'], 'primary' => '#6b705c']] ;
            $t['presets']['rosa'] = ['label' => ['es' => 'Rosa', 'en' => 'Rose'], 'colors' => [...$salvia['colors'], 'primary' => '#b5838d']];

            return $t;
        })
        ->json('editable.json', fn () => [
            'schema' => 1,
            'groups' => [['id' => 'cover', 'label' => ['es' => 'Portada', 'en' => 'Cover']], ['id' => 'look', 'label' => ['es' => 'Estilo', 'en' => 'Look']]],
            'fields' => [
                ['id' => 'cover_photo', 'type' => 'image', 'group' => 'cover', 'label' => ['es' => 'Foto', 'en' => 'Photo'], 'bind' => ['section' => 'cover', 'setting' => 'image', 'focus' => 'focus'], 'minWidth' => 600, 'aspect' => '4:5', 'plan' => 'free'],
                ['id' => 'cover_text', 'type' => 'text', 'group' => 'cover', 'label' => ['es' => 'Frase', 'en' => 'Phrase'], 'bind' => ['section' => 'cover', 'field' => 'text'], 'max' => ['es' => 40, 'en' => 40], 'multiline' => true, 'plan' => 'free'],
                ['id' => 'look_preset', 'type' => 'choice', 'group' => 'look', 'label' => ['es' => 'Paleta', 'en' => 'Palette'], 'bind' => ['theme' => 'preset'], 'choices' => [
                    ['value' => 'salvia', 'label' => ['es' => 'Salvia', 'en' => 'Sage']], ['value' => 'oliva', 'label' => ['es' => 'Oliva', 'en' => 'Olive']], ['value' => 'rosa', 'label' => ['es' => 'Rosa', 'en' => 'Rose']],
                ], 'plan' => 'free'],
                ['id' => 'color_primary', 'type' => 'color', 'group' => 'look', 'label' => ['es' => 'Color', 'en' => 'Color'], 'bind' => ['theme' => 'colors.primary'], 'plan' => 'full'],
                ['id' => 'ornaments', 'type' => 'toggle', 'group' => 'look', 'label' => ['es' => 'Adornos', 'en' => 'Ornaments'], 'bind' => ['option' => 'ornaments'], 'default' => true, 'plan' => 'free'],
            ],
        ]);
}

/** Installs and activates a package as the admin would, then signs the admin out. @return array<string, mixed> the package */
function edInstall(?PackageBuilder $builder = null, bool $activate = true): array
{
    $admin = User::factory()->create();
    $admin->assignRole('superadmin');
    test()->actingAsReauthed($admin);
    $b = $builder ?? edBuilder();
    $stage = test()->post('/api/v1/admin/templates/packages/validate', ['package' => UploadedFile::fake()->createWithContent('p.zip', (string) file_get_contents($b->zip()))], ['Accept' => 'application/json'])->assertOk()->json('data');
    expect($stage['valid'])->toBeTrue(json_encode($stage['report']['errors']));
    $package = test()->postJson('/api/v1/admin/templates/packages', ['stage_token' => $stage['stageToken'], 'reason' => 'Instalar el paquete'])->assertCreated()->json('data');
    if ($activate) {
        test()->postJson("/api/v1/admin/templates/packages/{$package['id']}/activate", [
            'reason' => 'Activar tras revisar',
            'checklist' => ['accessibility' => true, 'contrast' => true, 'long_names' => true, 'no_photo' => true, 'sections_reordered' => true, 'performance' => true],
        ])->assertOk();
    }
    app('auth')->forgetGuards();

    return $package;
}

function edPlan(Events $event, string $slug): Events
{
    $event->update(['event_plan_id' => EventPlan::where('slug', $slug)->value('id')]);

    return $event->refresh();
}

/** A draft quinceañera on the basic plan with an owner, an editor, a viewer and a stranger. @return array{0: Events, 1: array<string, User>} */
function edEvent(array $attributes = []): array
{
    $event = Events::factory()->draft()->create($attributes + [
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', 'basic')->value('id'),
        'event_name' => 'Los XV de Valentina',
        'custom_url_slug' => 'xv-de-valentina',
        'start_date' => now()->addMonths(3),
    ]);
    Guest::factory()->create(['event_id' => $event->id, 'code' => 'ED1234', 'parent_id' => null]);
    $members = [];
    foreach (['owner', 'editor', 'viewer'] as $role) {
        $members[$role] = User::factory()->create();
        EventUserRole::create(['event_id' => $event->id, 'user_id' => $members[$role]->id, 'role_id' => Role::where('name', $role)->value('id')]);
    }
    $members['stranger'] = User::factory()->create();

    return [$event, $members];
}

function edUrl(Events $event, string $path = ''): string
{
    return "/api/v1/app/event/{$event->id}/invitation" . ($path === '' ? '' : "/{$path}");
}

function edTemplateId(string $slug, string $type = 'quinceanera'): int
{
    return InvitationTemplate::where('slug', "{$slug}-{$type}")->value('id');
}

/** A real JPEG (noise-free gradient) of the given size, optionally with an EXIF block that carries GPS and orientation. */
function edJpeg(int $w = 900, int $h = 1200, ?int $orientation = null, bool $gps = false): string
{
    $im = imagecreatetruecolor($w, $h);
    for ($y = 0; $y < $h; $y += 4) {
        imagefilledrectangle($im, 0, $y, $w, $y + 3, imagecolorallocate($im, 40 + ($y * 150 / $h), 90, 160));
    }
    ob_start();
    imagejpeg($im, null, 85);
    $jpeg = (string) ob_get_clean();
    if ($orientation === null && ! $gps) {
        return $jpeg;
    }
    $tiff = edExif($orientation, $gps);
    $exif = "Exif\0\0" . $tiff;

    return substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($exif) + 2) . $exif . substr($jpeg, 2);
}

/** A little-endian TIFF block with an Orientation tag and (optionally) a GPS IFD with a latitude. */
function edExif(?int $orientation, bool $gps): string
{
    $entries = [];
    if ($orientation !== null) {
        $entries[] = pack('vvVv', 0x0112, 3, 1, $orientation) . "\0\0";
    }
    if ($gps) {
        // GPSInfo pointer to an IFD placed right after this one.
        $entries[] = 'GPS';
    }
    $count = count($entries);
    $ifd0Size = 2 + $count * 12 + 4;
    $body = pack('v', $count);
    $gpsOffset = 8 + $ifd0Size;
    foreach ($entries as $e) {
        $body .= $e === 'GPS' ? pack('vvVV', 0x8825, 4, 1, $gpsOffset) : $e;
    }
    $body .= pack('V', 0);
    if ($gps) {
        // GPS IFD: LatitudeRef "N" and Latitude 25/1, 45/1, 0/1 (Miami).
        $latOffset = $gpsOffset + 2 + 2 * 12 + 4;
        $body .= pack('v', 2) . pack('vvV', 1, 2, 2) . "N\0\0\0" . pack('vvVV', 2, 5, 3, $latOffset) . pack('V', 0);
        $body .= pack('VVVVVV', 25, 1, 45, 1, 0, 1);
    }

    return "II*\0" . pack('V', 8) . $body;
}

describe('GET invitation', function () {
    it('describes the editor from the editable.json of the package the event draws', function () {
        edInstall();
        [$event, $m] = edEvent(['template_id' => edTemplateId('festival')]);

        $data = $this->actingAs($m['owner'])->getJson(edUrl($event))->assertOk()->json('data');

        expect($data['template'])->toMatchArray(['slug' => 'festival-quinceanera', 'tier' => 'free', 'level' => 1, 'packageSlug' => 'festival', 'versionSemver' => '1.0.0', 'available' => true, 'fallbackToClassic' => false])
            ->and($data['state'])->toMatchArray(['status' => 'draft', 'stage' => 'invitation', 'pinned' => false, 'templateLocked' => false, 'unlocked' => false])
            ->and(array_column($data['groups'], 'id'))->toBe(['cover', 'look'])
            ->and(array_column($data['fields'], 'id'))->toBe(['cover_photo', 'cover_text', 'look_preset', 'color_primary', 'ornaments']);
        $byId = collect($data['fields'])->keyBy('id');
        expect($byId['cover_text'])->toMatchArray(['type' => 'text', 'plan' => 'free', 'locked' => false, 'isDefault' => true, 'multiline' => true, 'max' => ['es' => 40, 'en' => 40]])
            ->and($byId['cover_text']['value'])->toBe(['es' => 'Te espero.', 'en' => 'See you there.'])
            ->and($byId['color_primary'])->toMatchArray(['plan' => 'full', 'locked' => true, 'value' => '#566f52', 'default' => '#566f52'])
            ->and($byId['look_preset'])->toMatchArray(['value' => 'salvia', 'default' => 'salvia'])
            ->and($byId['ornaments'])->toMatchArray(['type' => 'toggle', 'value' => true])
            ->and($byId['cover_photo'])->toMatchArray(['value' => null, 'minWidth' => 600, 'aspect' => '4:5'])
            ->and($byId['cover_photo'])->not->toHaveKey('bind')
            ->and(array_column($data['sections'], 'type'))->toBe(['cover', 'rsvp'])
            ->and($data['sections'][0])->toMatchArray(['enabled' => true, 'order' => 1, 'required' => true]);
    });

    it('unlocks the paid fields on the full event and tells so', function () {
        edInstall();
        [$event, $m] = edEvent(['template_id' => edTemplateId('festival')]);
        edPlan($event, 'premium');

        $data = $this->actingAs($m['owner'])->getJson(edUrl($event))->assertOk()->json('data');

        expect($data['state']['unlocked'])->toBeTrue()
            ->and(collect($data['fields'])->firstWhere('id', 'color_primary')['locked'])->toBeFalse();
    });

    it('is readable by a viewer, closed to a stranger, and 401 without a session', function () {
        edInstall();
        [$event, $m] = edEvent(['template_id' => edTemplateId('festival')]);

        $this->getJson(edUrl($event))->assertUnauthorized();
        $this->actingAs($m['stranger'])->getJson(edUrl($event))->assertNotFound();
        $this->actingAs($m['viewer'])->getJson(edUrl($event))->assertOk();
    });

    it('answers with no fields and the classic fallback when the template is gone', function () {
        $package = edInstall();
        [$event, $m] = edEvent(['template_id' => edTemplateId('festival')]);
        $this->actingAs(User::factory()->create()->assignRole('superadmin'))->getJson('/api/v1/admin/userInfo');
        app('auth')->forgetGuards();
        InvitationTemplate::where('slug', 'festival-quinceanera')->update(['is_active' => false]);

        $data = $this->actingAs($m['owner'])->getJson(edUrl($event))->assertOk()->json('data');

        expect($data['template']['fallbackToClassic'])->toBeTrue()
            ->and($data['template']['slug'])->not->toBe('festival-quinceanera');
    });
});

describe('GET invitation/templates and impact', function () {
    it('lists the templates of the event type with their lock, the current one first', function () {
        edInstall(edBuilder('festival', 'free'));
        edInstall(edBuilder('galeria', 'premium'));
        [$event, $m] = edEvent(['template_id' => edTemplateId('festival')]);

        $list = $this->actingAs($m['owner'])->getJson(edUrl($event, 'templates'))->assertOk()->json('data');

        expect($list[0])->toMatchArray(['slug' => 'festival-quinceanera', 'current' => true, 'locked' => false, 'tier' => 'free', 'hasPresets' => true])
            ->and($list[0]['nameI18n'])->toBe(['es' => 'Fiesta festival', 'en' => 'Feast festival'])
            ->and(collect($list)->firstWhere('slug', 'galeria-quinceanera'))->toMatchArray(['locked' => true, 'current' => false, 'tier' => 'premium'])
            ->and(collect($list)->pluck('slug')->all())->not->toContain('festival-wedding');

        edPlan($event, 'premium');
        $list = $this->actingAs($m['owner'])->getJson(edUrl($event, 'templates'))->assertOk()->json('data');
        expect(collect($list)->firstWhere('slug', 'galeria-quinceanera')['locked'])->toBeFalse();
    });

    it('does not offer private, deactivated or withdrawn templates', function () {
        edInstall(edBuilder('festival', 'free'));
        $private = edInstall(edBuilder('secreta', 'free')->json('manifest.json', fn ($m) => [...$m, 'visibility' => 'private']));
        [$event, $m] = edEvent();
        InvitationTemplate::where('slug', 'festival-wedding')->update(['is_active' => false]);

        $slugs = collect($this->actingAs($m['owner'])->getJson(edUrl($event, 'templates'))->assertOk()->json('data'))->pluck('slug');

        expect($slugs)->toContain('festival-quinceanera')->not->toContain('secreta-quinceanera');
    });

    it('says what changing keeps and restarts', function () {
        edInstall(edBuilder('festival', 'free'));
        edInstall(edBuilder('galeria', 'premium'));
        [$event, $m] = edEvent(['template_id' => edTemplateId('festival'), 'template_config' => [
            'sections' => [['type' => 'cover', 'text' => ['es' => 'Mi frase']]],
            'theme' => ['preset' => 'rosa'],
            'package' => ['options' => ['ornaments' => false]],
        ]]);

        $impact = $this->actingAs($m['owner'])->getJson(edUrl($event, 'templates/' . edTemplateId('galeria') . '/impact'))->assertOk()->json('data');

        expect($impact)->toMatchArray(['tier' => 'premium', 'locked' => true, 'current' => false, 'willPin' => false, 'restoresFromStash' => false, 'changesPublishedLook' => false])
            ->and(array_column($impact['keeps'], 'kind'))->toBe(['sections', 'texts'])
            ->and($impact['resets'][0])->toBe(['kind' => 'preset', 'from' => 'rosa', 'to' => 'salvia'])
            ->and(array_column($impact['resets'], 'kind'))->toBe(['preset', 'options']);
    });

    it('answers 404 template_not_found and 403 template_not_available on the impact', function () {
        edInstall(edBuilder('festival', 'free'));
        $withdrawn = edInstall(edBuilder('retirada', 'free'));
        [$event, $m] = edEvent();
        InvitationTemplate::where('slug', 'retirada-quinceanera')->update(['is_active' => false]);

        $this->actingAs($m['owner'])->getJson(edUrl($event, 'templates/999999/impact'))->assertNotFound()->assertJson(['code' => 'template_not_found']);
        $this->actingAs($m['owner'])->getJson(edUrl($event, 'templates/abc/impact'))->assertNotFound()->assertJson(['code' => 'template_not_found']);
        $this->actingAs($m['owner'])->getJson(edUrl($event, 'templates/' . edTemplateId('retirada') . '/impact'))->assertForbidden()->assertJson(['code' => 'template_not_available']);
    });
});

describe('templateFor with the plan (D-4)', function () {
    it('draws the free template when a premium one is not paid and the event is not fixed', function () {
        edInstall(edBuilder('galeria', 'premium'));
        [$event, $m] = edEvent(['template_id' => edTemplateId('galeria')]);
        $this->actingAs($m['owner']);

        $data = $this->getJson(edUrl($event))->assertOk()->json('data');
        expect($data['template']['tier'])->toBe('free')->and($data['template']['fallbackToClassic'])->toBeTrue();

        $public = $this->getJson('/api/v1/app/invitation/xv-de-valentina/ED1234');
        // Only published events have a public invitation.
        $public->assertNotFound();
    });

    it('keeps what a fixed event paid for, and filters free colors only on drafts without the plan', function () {
        edInstall(edBuilder('galeria', 'premium'));
        [$event] = edEvent(['template_id' => edTemplateId('galeria'), 'status' => 'published', 'template_config' => ['theme' => ['colors' => ['primary' => '#123456']]]]);
        $guest = Guest::where('event_id', $event->id)->first();

        $filtered = app(\App\Http\Services\AppServices\InvitationService::class)->build($event->fresh(), $guest);
        expect($filtered['theme']['name'])->not->toBe('galeria')
            ->and($filtered['theme']['colors']['primary'])->not->toBe('#123456');

        edPlan($event, 'premium');
        $paid = app(\App\Http\Services\AppServices\InvitationService::class)->build($event->fresh(), $guest);
        expect($paid['theme']['name'])->toBe('galeria')->and($paid['theme']['colors']['primary'])->toBe('#123456');

        // A refund after the invitation was fixed: the look does not change.
        $event->update(['template_version_id' => InvitationTemplate::find(edTemplateId('galeria'))->current_version_id]);
        edPlan($event, 'basic');
        $kept = app(\App\Http\Services\AppServices\InvitationService::class)->build($event->fresh(), $guest);
        expect($kept['theme']['name'])->toBe('galeria')->and($kept['theme']['colors']['primary'])->toBe('#123456');
    });
});

describe('PATCH invitation', function () {
    function edPatch($test, Events $event, User $user, array $fields)
    {
        return $test->actingAs($user)->patchJson(edUrl($event), ['fields' => $fields]);
    }

    function edSetup(string $tier = 'free', string $plan = 'basic'): array
    {
        edInstall(edBuilder('festival', $tier));
        [$event, $m] = edEvent(['template_id' => edTemplateId('festival')]);
        edPlan($event, $plan);

        return [$event, $m];
    }

    it('saves every kind of field by id and translates it by bind', function () {
        [$event, $m] = edSetup('free', 'premium');

        $response = edPatch($this, $event, $m['owner'], [
            'cover_text' => ['es' => 'Te espero', 'en' => 'See you'],
            'look_preset' => 'rosa',
            'color_primary' => '#7b2d5b',
            'ornaments' => false,
            'cover_photo' => ['focus' => '40% 25%'],
        ])->assertOk();

        expect($event->fresh()->template_config)->toEqual([
            'sections' => [['type' => 'cover', 'text' => ['es' => 'Te espero', 'en' => 'See you'], 'settings' => ['focus' => '40% 25%']]],
            'theme' => ['preset' => 'rosa', 'colors' => ['primary' => '#7b2d5b']],
            'package' => ['options' => ['ornaments' => false]],
        ]);
        $fields = collect($response->json('data.fields'))->keyBy('id');
        expect($fields->keys()->all())->toBe(['cover_photo', 'cover_text', 'look_preset', 'color_primary', 'ornaments'])
            ->and($fields['cover_text'])->toMatchArray(['value' => ['es' => 'Te espero', 'en' => 'See you'], 'isDefault' => false])
            ->and($fields['look_preset'])->toMatchArray(['value' => 'rosa', 'isDefault' => false])
            ->and($fields['ornaments']['value'])->toBeFalse()
            ->and($response->json('data.savedAt'))->not->toBeNull();
    });

    it('is partial per field, and null goes back to the template value', function () {
        [$event, $m] = edSetup('free', 'premium');
        edPatch($this, $event, $m['owner'], ['cover_text' => ['es' => 'Hola', 'en' => 'Hi'], 'look_preset' => 'oliva'])->assertOk();

        $res = edPatch($this, $event, $m['owner'], ['cover_text' => ['en' => 'Hello'], 'look_preset' => null])->assertOk();

        expect($event->fresh()->template_config)->toEqual(['sections' => [['type' => 'cover', 'text' => ['es' => 'Hola', 'en' => 'Hello']]]])
            ->and(collect($res->json('data.fields'))->firstWhere('id', 'look_preset'))->toMatchArray(['value' => 'salvia', 'isDefault' => true]);

        edPatch($this, $event, $m['owner'], ['cover_text' => null])->assertOk();
        expect($event->fresh()->template_config)->toBeNull();
    });

    it('answers a code per field in 422', function () {
        [$event, $m] = edSetup('free', 'premium');
        $cases = [
            ['cover_text', ['es' => 'Hola <b>mundo</b>'], 'fields.cover_text.es', 'invalid_type'],
            ['cover_text', ['es' => str_repeat('x', 41)], 'fields.cover_text.es', 'too_long'],
            ['cover_text', ['fr' => 'Bonjour'], 'fields.cover_text.fr', 'unknown_field'],
            ['cover_text', 'plano', 'fields.cover_text', 'invalid_type'],
            ['color_primary', 'rojo', 'fields.color_primary', 'invalid_color'],
            ['look_preset', 'inexistente', 'fields.look_preset', 'invalid_choice'],
            ['ornaments', 'yes', 'fields.ornaments', 'invalid_type'],
            ['cover_photo', ['focus' => 'arriba'], 'fields.cover_photo', 'invalid_focus'],
            ['cover_photo', ['image' => 'upload:images/event/1/cover/x.webp'], 'fields.cover_photo', 'unknown_field'],
            ['cover_photo', ['url' => 'https://evil.test/x.jpg'], 'fields.cover_photo', 'unknown_field'],
        ];
        foreach ($cases as [$id, $value, $key, $code]) {
            $res = edPatch($this, $event, $m['owner'], [$id => $value])->assertUnprocessable();
            expect($res->json('errors')[$key][0] ?? null)->toBe($code, "{$id}: " . json_encode($res->json('errors')));
        }
        expect($event->fresh()->template_config)->toBeNull();
    });

    it('rejects the whole request when one field is wrong: nothing is saved', function () {
        [$event, $m] = edSetup('free', 'premium');

        edPatch($this, $event, $m['owner'], ['look_preset' => 'rosa', 'color_primary' => 'rojo'])->assertUnprocessable();

        expect($event->fresh()->template_config)->toBeNull();
    });

    it('answers 404 field_not_found for an id that is not in the form, including prototype keys', function () {
        [$event, $m] = edSetup();

        foreach (['nope', '__proto__', 'constructor', 'bind', 'sections'] as $id) {
            edPatch($this, $event, $m['owner'], [$id => 'x'])->assertNotFound()->assertJson(['code' => 'field_not_found']);
        }
    });

    it('answers 422 for a body that is not an object of fields', function () {
        [$event, $m] = edSetup();

        $this->actingAs($m['owner'])->patchJson(edUrl($event), [])->assertUnprocessable()->assertJsonPath('errors.fields.0', 'required');
        $this->actingAs($m['owner'])->patchJson(edUrl($event), ['fields' => ['a', 'b']])->assertUnprocessable()->assertJsonPath('errors.fields.0', 'invalid_type');
        $this->actingAs($m['owner'])->patchJson(edUrl($event), ['fields' => 'x'])->assertUnprocessable()->assertJsonPath('errors.fields.0', 'invalid_type');
    });

    it('asks for the full event on a paid field and lets the free ones through, never saving a half', function () {
        [$event, $m] = edSetup('free', 'basic');

        edPatch($this, $event, $m['owner'], ['color_primary' => '#112233'])->assertForbidden()->assertJson(['code' => 'plan_required']);
        edPatch($this, $event, $m['owner'], ['look_preset' => 'rosa', 'color_primary' => '#112233'])->assertForbidden();
        expect($event->fresh()->template_config)->toBeNull();

        edPatch($this, $event, $m['owner'], ['look_preset' => 'rosa'])->assertOk();
        // Going back to the template's value never needs the plan.
        edPatch($this, $event, $m['owner'], ['color_primary' => null])->assertOk();
    });

    it('lets an editor write, and a viewer or a stranger not', function () {
        [$event, $m] = edSetup();

        $this->patchJson(edUrl($event), ['fields' => ['look_preset' => 'oliva']])->assertUnauthorized();
        edPatch($this, $event, $m['editor'], ['look_preset' => 'rosa'])->assertOk();
        edPatch($this, $event, $m['viewer'], ['look_preset' => 'oliva'])->assertForbidden();
        edPatch($this, $event, $m['stranger'], ['look_preset' => 'oliva'])->assertNotFound();
        expect($event->fresh()->template_config['theme']['preset'])->toBe('rosa');
    });

    it('merges into what is stored: two edits of different fields both stay', function () {
        [$event, $m] = edSetup();
        edPatch($this, $event, $m['owner'], ['look_preset' => 'rosa'])->assertOk();
        edPatch($this, $event, $m['owner'], ['ornaments' => false])->assertOk();

        expect($event->fresh()->template_config)->toEqual(['theme' => ['preset' => 'rosa'], 'package' => ['options' => ['ornaments' => false]]]);
    });

    it('prunes what the version no longer knows into the stash, and keeps the content of the event', function () {
        [$event, $m] = edSetup('free', 'premium');
        $event->update(['template_config' => [
            'sections' => [['type' => 'cover', 'text' => ['es' => 'Mi frase'], 'settings' => ['legacy' => 'x']]],
            'theme' => ['preset' => 'rosa', 'colors' => ['accent' => '#000000']],
            'package' => ['options' => ['viejo' => true]],
        ]]);

        edPatch($this, $event, $m['owner'], ['ornaments' => false])->assertOk();

        $fresh = $event->fresh();
        expect($fresh->template_config['theme'])->toBe(['preset' => 'rosa'])
            ->and($fresh->template_config['package'])->toBe(['options' => ['ornaments' => false]])
            ->and($fresh->template_config['sections'][0]['text'])->toBe(['es' => 'Mi frase'])
            ->and($fresh->template_config_stash[(string) edTemplateId('festival')]['theme']['colors'])->toBe(['accent' => '#000000'])
            ->and($fresh->template_config_stash[(string) edTemplateId('festival')]['package']['options'])->toBe(['viejo' => true]);
    });

    it('refuses a config that grows past 16 KB', function () {
        [$event, $m] = edSetup('free', 'premium');
        $event->update(['template_config' => ['sections' => [['type' => 'cover', 'title' => ['es' => str_repeat('a', 20000)]]]]]);

        edPatch($this, $event, $m['owner'], ['ornaments' => false])->assertUnprocessable()->assertJsonPath('errors.fields.0', 'config_too_large');
    });

    it('writes one activity entry per field and burst, with the id and never the value', function () {
        [$event, $m] = edSetup();
        Cache::flush();

        edPatch($this, $event, $m['owner'], ['cover_text' => ['es' => 'Dato personal']])->assertOk();
        edPatch($this, $event, $m['owner'], ['cover_text' => ['es' => 'Otro dato']])->assertOk();
        edPatch($this, $event, $m['owner'], ['ornaments' => false])->assertOk();

        $logged = EventActivity::where('event_id', $event->id)->where('type', 'invitation_field_changed')->get();
        expect($logged)->toHaveCount(2)
            ->and($logged->pluck('payload.field')->all())->toBe(['cover_text', 'ornaments'])
            ->and(json_encode($logged->pluck('payload')))->not->toContain('dato');
    });

    it('refuses writes on an archived event', function () {
        [$event, $m] = edSetup();
        $event->update(['status' => 'archived']);

        edPatch($this, $event, $m['owner'], ['look_preset' => 'rosa'])->assertUnprocessable()->assertJsonPath('errors.status.0', 'event_archived');
    });

    it('answers 429 after 60 writes a minute', function () {
        [$event, $m] = edSetup();
        $this->actingAs($m['owner']);

        for ($i = 0; $i < 60; $i++) {
            $this->patchJson(edUrl($event), ['fields' => ['ornaments' => $i % 2 === 0]])->assertOk();
        }

        $this->patchJson(edUrl($event), ['fields' => ['ornaments' => true]])->assertTooManyRequests();
    });
});

describe('PATCH invitation/sections', function () {
    function edSections($test, Events $event, User $user, array $sections)
    {
        return $test->actingAs($user)->patchJson(edUrl($event, 'sections'), ['sections' => $sections]);
    }

    it('turns sections on and off and orders them', function () {
        edInstall(edBuilder());
        [$event, $m] = edEvent(['template_id' => edTemplateId('festival')]);
        edPlan($event, 'premium');

        $res = edSections($this, $event, $m['owner'], [['type' => 'closing', 'enabled' => true, 'order' => 5], ['type' => 'rsvp', 'order' => 4]])->assertOk();

        expect(array_column($res->json('data.sections'), 'type'))->toBe(['cover', 'rsvp', 'closing'])
            ->and(collect($res->json('data.sections'))->firstWhere('type', 'closing'))->toMatchArray(['enabled' => true, 'order' => 5]);
        expect($event->fresh()->template_config['sections'])->toContain(['type' => 'closing', 'enabled' => true, 'order' => 5]);
    });

    it('answers a code per section', function () {
        edInstall(edBuilder());
        [$event, $m] = edEvent(['template_id' => edTemplateId('festival')]);
        $cases = [
            [[['type' => 'cover', 'enabled' => false]], 'sections.0.enabled', 'required_section'],
            [[['type' => 'rsvp', 'enabled' => false]], 'sections.0.enabled', 'required_section'],
            [[['type' => 'gallery', 'enabled' => true]], 'sections.0.enabled', 'module_locked'],
            [[['type' => 'rsvp', 'order' => 1]], 'sections.0.order', 'cover_first'],
            [[['type' => 'cover', 'order' => 9]], 'sections.0.order', 'cover_first'],
            [[['type' => 'nope', 'enabled' => true]], 'sections.0.type', 'invalid_type'],
            [[['type' => 'closing', 'enabled' => 'si']], 'sections.0.enabled', 'invalid_type'],
            [[['type' => 'closing', 'order' => 500]], 'sections.0.order', 'invalid_type'],
            [[['type' => 'closing', 'title' => ['es' => 'x']]], 'sections.0', 'invalid_type'],
            [[['type' => 'closing'], ['type' => 'closing']], 'sections.1.type', 'invalid_type'],
        ];
        foreach ($cases as [$body, $key, $code]) {
            $res = edSections($this, $event, $m['owner'], $body)->assertUnprocessable();
            expect($res->json('errors')[$key][0] ?? null)->toBe($code, json_encode($body) . ' ' . json_encode($res->json('errors')));
        }
        expect($event->fresh()->template_config)->toBeNull();
        edSections($this, $event, $m['owner'], [])->assertUnprocessable()->assertJsonPath('errors.sections.0', 'required');
    });

    it('lets a module section on once the plan has the module', function () {
        edInstall(edBuilder());
        [$event, $m] = edEvent(['template_id' => edTemplateId('festival')]);
        edPlan($event, 'premium');

        edSections($this, $event, $m['owner'], [['type' => 'gallery', 'enabled' => true, 'order' => 6]])->assertOk();
    });
});

describe('PUT invitation/template', function () {
    function edPut($test, Events $event, User $user, mixed $id)
    {
        return $test->actingAs($user)->putJson(edUrl($event, 'template'), ['template_id' => $id]);
    }

    it('applies a free template, keeps the content, puts the look aside and offers undo', function () {
        edInstall(edBuilder('festival', 'free'));
        edInstall(edBuilder('galeria', 'free'));
        [$event, $m] = edEvent(['template_id' => edTemplateId('festival')]);
        edPatch($this, $event, $m['owner'], ['cover_text' => ['es' => 'Mi frase'], 'look_preset' => 'rosa', 'ornaments' => false])->assertOk();

        $res = edPut($this, $event, $m['owner'], edTemplateId('galeria'))->assertOk();

        $fresh = $event->fresh();
        expect($fresh->template_id)->toBe(edTemplateId('galeria'))
            ->and($fresh->template_version_id)->toBeNull()
            ->and($fresh->template_config)->toEqual(['sections' => [['type' => 'cover', 'text' => ['es' => 'Mi frase']]]])
            ->and($fresh->template_config_stash[(string) edTemplateId('festival')]['theme'])->toBe(['preset' => 'rosa'])
            ->and($res->json('data.template.slug'))->toBe('galeria-quinceanera')
            ->and($res->json('data.undo.templateId'))->toBe(edTemplateId('festival'))
            ->and($res->json('data.undo.until'))->not->toBeNull();
        $activity = EventActivity::where('event_id', $event->id)->where('type', 'invitation_template_changed')->first();
        expect($activity->payload)->toMatchArray(['from' => edTemplateId('festival'), 'to' => edTemplateId('galeria')]);
    });

    it('undoes exactly: preset, options and free colors come back', function () {
        edInstall(edBuilder('festival', 'free'));
        edInstall(edBuilder('galeria', 'free'));
        [$event, $m] = edEvent(['template_id' => edTemplateId('festival')]);
        edPlan($event, 'premium');
        edPatch($this, $event, $m['owner'], ['look_preset' => 'rosa', 'ornaments' => false, 'color_primary' => '#abcdef', 'cover_text' => ['es' => 'Hola']])->assertOk();
        $before = $event->fresh()->template_config;

        edPut($this, $event, $m['owner'], edTemplateId('galeria'))->assertOk();
        // The free color follows (same bind in the new template); preset and options restart.
        expect($event->fresh()->template_config['theme'])->toBe(['colors' => ['primary' => '#abcdef']]);

        $this->actingAs($m['owner'])->putJson(edUrl($event, 'template'), ['template_id' => edTemplateId('festival'), 'undo' => true])->assertOk();

        expect($event->fresh()->template_config)->toEqualCanonicalizing($before)
            ->and(EventActivity::where('event_id', $event->id)->where('type', 'invitation_template_undone')->count())->toBe(1);
    });

    it('answers every refusal with its code', function () {
        edInstall(edBuilder('festival', 'free'));
        edInstall(edBuilder('galeria', 'premium'));
        edInstall(edBuilder('secreta', 'free')->json('manifest.json', fn ($m) => [...$m, 'visibility' => 'private']));
        edInstall(edBuilder('apagada', 'free'));
        InvitationTemplate::where('slug', 'apagada-quinceanera')->update(['is_active' => false]);
        [$event, $m] = edEvent(['template_id' => edTemplateId('festival')]);

        edPut($this, $event, $m['owner'], edTemplateId('galeria'))->assertForbidden()->assertJson(['code' => 'plan_required']);
        edPut($this, $event, $m['owner'], edTemplateId('secreta'))->assertForbidden()->assertJson(['code' => 'template_not_available']);
        edPut($this, $event, $m['owner'], edTemplateId('apagada'))->assertForbidden()->assertJson(['code' => 'template_not_available']);
        edPut($this, $event, $m['owner'], edTemplateId('festival', 'wedding'))->assertForbidden()->assertJson(['code' => 'template_not_available']);
        edPut($this, $event, $m['owner'], 999999)->assertNotFound()->assertJson(['code' => 'template_not_found']);
        edPut($this, $event, $m['owner'], null)->assertUnprocessable()->assertJsonPath('errors.template_id.0', 'required');
        edPut($this, $event, $m['owner'], 'abc')->assertUnprocessable()->assertJsonPath('errors.template_id.0', 'invalid');
        edPut($this, $event, $m['viewer'], edTemplateId('festival'))->assertForbidden();
        expect($event->fresh()->template_id)->toBe(edTemplateId('festival'));

        edPlan($event, 'premium');
        edPut($this, $event, $m['owner'], edTemplateId('galeria'))->assertOk();
    });

    it('is locked once the full invitation is published (D-1), but not for the Save the date (D-3)', function () {
        edInstall(edBuilder('festival', 'free'));
        edInstall(edBuilder('galeria', 'free'));
        [$event, $m] = edEvent(['template_id' => edTemplateId('festival')]);

        $this->actingAs($m['owner'])->postJson("/api/v1/app/event/{$event->id}/publish", ['stage' => 'save_the_date'])->assertOk();
        expect($event->fresh()->template_version_id)->toBeNull();
        edPut($this, $event, $m['owner'], edTemplateId('galeria'))->assertOk();
        $this->getJson(edUrl($event))->assertJsonPath('data.state.templateLocked', false);

        $this->actingAs($m['owner'])->postJson("/api/v1/app/event/{$event->id}/publish", ['stage' => 'invitation'])->assertOk();
        // Going from the Save the date to the invitation fixes the version she chose in between.
        expect($event->fresh()->template_version_id)->toBe(InvitationTemplate::find(edTemplateId('galeria'))->current_version_id);

        edPut($this, $event, $m['owner'], edTemplateId('festival'))->assertConflict()->assertJson(['code' => 'template_locked']);
        $this->getJson(edUrl($event))->assertJsonPath('data.state.templateLocked', true)->assertJsonPath('data.state.pinned', true);
        expect($event->fresh()->template_id)->toBe(edTemplateId('galeria'));
    });

    it('is a no change when the template is already the one', function () {
        edInstall(edBuilder('festival', 'free'));
        [$event, $m] = edEvent(['template_id' => edTemplateId('festival')]);
        edPatch($this, $event, $m['owner'], ['look_preset' => 'rosa'])->assertOk();

        $res = edPut($this, $event, $m['owner'], edTemplateId('festival'))->assertOk();

        expect($res->json('data.undo'))->toBeNull()
            ->and($event->fresh()->template_config['theme']['preset'])->toBe('rosa')
            ->and(EventActivity::where('event_id', $event->id)->where('type', 'invitation_template_changed')->count())->toBe(0);
    });

    it('purges the stash when the invitation is published', function () {
        edInstall(edBuilder('festival', 'free'));
        edInstall(edBuilder('galeria', 'free'));
        [$event, $m] = edEvent(['template_id' => edTemplateId('festival')]);
        edPatch($this, $event, $m['owner'], ['look_preset' => 'rosa'])->assertOk();
        edPut($this, $event, $m['owner'], edTemplateId('galeria'))->assertOk();
        expect($event->fresh()->template_config_stash)->not->toBeNull();

        $this->postJson("/api/v1/app/event/{$event->id}/publish")->assertOk();

        expect($event->fresh()->template_config_stash)->toBeNull();
    });
});

describe('publish with expected_version_id', function () {
    it('publishes when the version is the one she saw and answers 409 when an admin activated another', function () {
        edInstall(edBuilder('festival', 'free'));
        [$event, $m] = edEvent(['template_id' => edTemplateId('festival')]);
        $seen = $this->actingAs($m['owner'])->getJson(edUrl($event))->json('data.template.versionId');

        edInstall(edBuilder('festival', 'free')->json('manifest.json', fn ($mm) => [...$mm, 'slug' => 'festival', 'version' => '1.1.0', 'tier' => 'free', 'name' => ['es' => 'Fiesta festival', 'en' => 'Feast festival']]));
        $this->actingAs($m['owner'])->postJson("/api/v1/app/event/{$event->id}/publish", ['expected_version_id' => $seen])
            ->assertConflict()->assertJson(['code' => 'template_version_changed'])->assertJsonPath('currentVersionId', fn ($v) => $v !== $seen);
        expect($event->fresh()->status)->toBe('draft');

        $now = $this->getJson(edUrl($event))->json('data.template.versionId');
        $this->postJson("/api/v1/app/event/{$event->id}/publish", ['expected_version_id' => $now])->assertOk();
        expect($event->fresh()->template_version_id)->toBe($now);
    });

    it('keeps the old behavior without the field', function () {
        edInstall(edBuilder('festival', 'free'));
        [$event, $m] = edEvent(['template_id' => edTemplateId('festival')]);

        $this->actingAs($m['owner'])->postJson("/api/v1/app/event/{$event->id}/publish")->assertOk();
        $this->postJson("/api/v1/app/event/{$event->id}/publish", ['expected_version_id' => 'x'])->assertUnprocessable();
    });
});
