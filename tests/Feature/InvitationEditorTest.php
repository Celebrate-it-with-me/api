<?php

use App\Http\Services\Account\EventPurger;
use App\Http\Services\AppServices\EventTemplateConfigValidator;
use App\Http\Services\AppServices\Invitation\CoverPhoto;
use App\Http\Services\AppServices\InvitationService;
use App\Models\EventActivity;
use App\Models\EventLocation;
use App\Models\EventPlan;
use App\Models\Events;
use App\Models\EventType;
use App\Models\EventUserRole;
use App\Models\Guest;
use App\Models\InvitationTemplate;
use App\Models\TemplatePackage;
use App\Models\User;
use App\Support\AdminPermissions;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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
            $t['presets']['oliva'] = ['label' => ['es' => 'Oliva', 'en' => 'Olive'], 'colors' => [...$salvia['colors'], 'primary' => '#6b705c']];
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
            ->and($list[0]['presets'][1])->toEqual(['value' => 'oliva', 'key' => 'oliva', 'name' => 'Oliva', 'label' => ['es' => 'Oliva', 'en' => 'Olive'], 'colors' => ['#fbf8f1', '#8c8658', '#6b705c'], 'palette' => ['background' => '#fbf8f1', 'text' => '#34382c', 'accent' => '#8c8658']])
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

        $filtered = app(InvitationService::class)->build($event->fresh(), $guest);
        expect($filtered['theme']['name'])->not->toBe('galeria')
            ->and($filtered['theme']['colors']['primary'])->not->toBe('#123456');

        edPlan($event, 'premium');
        $paid = app(InvitationService::class)->build($event->fresh(), $guest);
        expect($paid['theme']['name'])->toBe('galeria')->and($paid['theme']['colors']['primary'])->toBe('#123456');

        // A refund after the invitation was fixed: the look does not change.
        $event->update(['template_version_id' => InvitationTemplate::find(edTemplateId('galeria'))->current_version_id]);
        edPlan($event, 'basic');
        $kept = app(InvitationService::class)->build($event->fresh(), $guest);
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

    it('keeps the closing last, whatever the template says, and refuses to move it before another section', function () {
        edInstall(edBuilder());
        [$event, $m] = edEvent(['template_id' => edTemplateId('festival')]);
        edPlan($event, 'premium');

        // Another section placed after the closing: the closing still reads as the last one.
        $res = edSections($this, $event, $m['owner'], [['type' => 'closing', 'enabled' => true], ['type' => 'rsvp', 'order' => 9]])->assertOk();
        $types = array_column($res->json('data.sections'), 'type');
        expect(end($types))->toBe('closing')
            ->and(collect($res->json('data.sections'))->firstWhere('type', 'closing')['order'])->toBeGreaterThan(9);

        // Explicitly moving it in front of the rest is refused with a structured error.
        $res = edSections($this, $event, $m['owner'], [['type' => 'closing', 'order' => 2]])->assertUnprocessable();
        expect($res->json('errors')['sections.0.order'][0])->toBe('closing_last');
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
            [[['type' => 'closing', 'order' => 1]], 'sections.0.order', 'closing_last'],
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

describe('cover photo', function () {
    function edUpload($test, Events $event, User $user, string|UploadedFile $file, array $extra = [])
    {
        $upload = $file instanceof UploadedFile ? $file : UploadedFile::fake()->createWithContent('foto.jpg', $file);

        return $test->actingAs($user)->post(edUrl($event, 'cover-photo'), ['photo' => $upload] + $extra, ['Accept' => 'application/json']);
    }

    function edPhotoEvent(string $plan = 'basic'): array
    {
        edInstall(edBuilder('festival', 'free'));
        [$event, $m] = edEvent(['template_id' => edTemplateId('festival')]);
        edPlan($event, $plan);

        return [$event, $m];
    }

    it('stores a WebP cover and thumbnail named by event, and the editor and the invitation serve them', function () {
        [$event, $m] = edPhotoEvent();

        $res = edUpload($this, $event, $m['owner'], edJpeg(1800, 2400), ['focus' => '40% 25%'])->assertCreated();

        $data = $res->json('data');
        expect($data)->toMatchArray(['width' => 1440, 'height' => 1920, 'focus' => '40% 25%'])
            ->and($data['bytes'])->toBeGreaterThan(1000)
            ->and($data['url'])->toContain("images/event/{$event->id}/cover/")->and($data['thumbUrl'])->toEndWith('-thumb.webp');
        $stored = $event->fresh()->template_config['sections'][0]['settings'];
        expect($stored['image'])->toStartWith("upload:images/event/{$event->id}/cover/")->and($stored['focus'])->toBe('40% 25%');
        $path = substr($stored['image'], 7);
        $disk = Storage::disk('event-media');
        expect($disk->exists($path))->toBeTrue()->and($disk->exists(CoverPhoto::thumbPath($path)))->toBeTrue()
            ->and(getimagesizefromstring($disk->get($path))[0])->toBe(1440)
            ->and(getimagesizefromstring($disk->get($path))['mime'])->toBe('image/webp')
            ->and(getimagesizefromstring($disk->get(CoverPhoto::thumbPath($path)))[0])->toBe(480);

        $field = collect($this->getJson(edUrl($event))->json('data.fields'))->firstWhere('id', 'cover_photo');
        expect($field['value'])->toMatchArray(['width' => 1440, 'height' => 1920, 'focus' => '40% 25%'])->and($field['isDefault'])->toBeFalse();

        $this->postJson("/api/v1/app/event/{$event->id}/publish")->assertOk();
        $public = $this->getJson('/api/v1/app/invitation/xv-de-valentina/ED1234')->assertOk()->json('data.sections.0.data');
        expect($public['image'])->toBe($data['url'])->and($public['focus'])->toBe('40% 25%');
    });

    it('carries a null focus when none was chosen', function () {
        [$event] = edPhotoEvent();
        $event->update(['status' => 'published']);

        $cover = $this->getJson('/api/v1/app/invitation/xv-de-valentina/ED1234')->assertOk()->json('data.sections.0.data');

        expect(array_keys($cover))->toBe(['eventName', 'description', 'image', 'focus'])->and($cover['focus'])->toBeNull();
    });

    it('drops every piece of metadata and applies the orientation', function () {
        [$event, $m] = edPhotoEvent();
        $source = edJpeg(900, 1200, 6, true);
        expect(@exif_read_data('data://image/jpeg;base64,' . base64_encode($source), 'GPS', true)['GPS'] ?? null)->not->toBeNull('the fixture must carry GPS');

        $res = edUpload($this, $event, $m['owner'], $source)->assertCreated();

        // Rotated by the EXIF orientation 6: the portrait became a landscape.
        expect($res->json('data.width'))->toBe(1200)->and($res->json('data.height'))->toBe(900);
        $bytes = Storage::disk('event-media')->get(substr($event->fresh()->template_config['sections'][0]['settings']['image'], 7));
        expect(str_contains($bytes, 'EXIF'))->toBeFalse()->and(str_contains($bytes, 'GPS'))->toBeFalse()->and(str_contains($bytes, 'Exif'))->toBeFalse()
            ->and(substr($bytes, 0, 4))->toBe('RIFF')->and(substr($bytes, 8, 4))->toBe('WEBP');
    });

    it('answers a code per kind of bad file and keeps the photo she had', function () {
        [$event, $m] = edPhotoEvent();
        edUpload($this, $event, $m['owner'], edJpeg())->assertCreated();
        $before = $event->fresh()->template_config;

        $gif = (function () {
            $im = imagecreatetruecolor(700, 700);
            ob_start();
            imagegif($im);

            return (string) ob_get_clean();
        })();
        $tooWide = (function () {
            $im = imagecreatetruecolor(8001, 10);
            ob_start();
            imagepng($im, null, 9);

            return (string) ob_get_clean();
        })();
        $tooManyPixels = (function () {
            $im = imagecreatetruecolor(7000, 6000);
            ob_start();
            imagepng($im, null, 9);

            return (string) ob_get_clean();
        })();
        $tiny = (function () {
            $im = imagecreatetruecolor(100, 100);
            ob_start();
            imagejpeg($im);

            return (string) ob_get_clean();
        })();
        $cases = [
            'not_an_image' => UploadedFile::fake()->createWithContent('a.jpg', 'esto es texto plano'),
            'unsupported_type' => UploadedFile::fake()->createWithContent('a.jpg', $gif),
            'corrupt' => UploadedFile::fake()->createWithContent('a.jpg', "\xFF\xD8\xFF\xE0" . random_bytes(300)),
            'too_many_pixels' => UploadedFile::fake()->createWithContent('a.png', $tooWide),
            'too_small' => UploadedFile::fake()->createWithContent('a.jpg', $tiny),
            'too_large' => UploadedFile::fake()->create('big.jpg', 11 * 1024, 'image/jpeg'),
            'required' => UploadedFile::fake()->create('empty.jpg', 0),
        ];
        foreach ($cases as $code => $file) {
            $res = edUpload($this, $event, $m['owner'], $file)->assertUnprocessable();
            expect($res->json('errors.photo.0'))->toBe($code, json_encode($res->json()));
        }
        $this->actingAs($m['owner'])->post(edUrl($event, 'cover-photo'), [], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonPath('errors.photo.0', 'required');
        edUpload($this, $event, $m['owner'], edJpeg(), ['focus' => 'arriba'])->assertUnprocessable()->assertJsonPath('errors.focus.0', 'invalid_focus');
        expect($event->fresh()->template_config)->toEqual($before)->and(Storage::disk('event-media')->allFiles())->toHaveCount(3);
    });

    it('accepts a huge picture only up to the pixel limit, without exhausting memory', function () {
        [$event, $m] = edPhotoEvent();
        $im = imagecreatetruecolor(6000, 5000);
        ob_start();
        imagejpeg($im, null, 20);
        $big = (string) ob_get_clean();

        edUpload($this, $event, $m['owner'], $big)->assertCreated()->assertJsonPath('data.width', 1440);
    });

    it('replaces the photo and deletes the old files only after the new one is saved', function () {
        [$event, $m] = edPhotoEvent();
        edUpload($this, $event, $m['owner'], edJpeg())->assertCreated();
        $first = substr($event->fresh()->template_config['sections'][0]['settings']['image'], 7);

        edUpload($this, $event, $m['owner'], edJpeg(800, 1000))->assertCreated();

        $second = substr($event->fresh()->template_config['sections'][0]['settings']['image'], 7);
        expect($second)->not->toBe($first)->and(Storage::disk('event-media')->exists($first))->toBeFalse()
            ->and(Storage::disk('event-media')->allFiles())->toHaveCount(3);
    });

    it('removes the photo, idempotently', function () {
        [$event, $m] = edPhotoEvent();
        edUpload($this, $event, $m['owner'], edJpeg(), ['focus' => '10% 10%'])->assertCreated();

        $this->actingAs($m['owner'])->deleteJson(edUrl($event, 'cover-photo'))->assertOk()->assertExactJson(['data' => null]);
        $this->deleteJson(edUrl($event, 'cover-photo'))->assertOk();

        expect($event->fresh()->template_config)->toBeNull()->and(Storage::disk('event-media')->allFiles())->toBe([]);
        expect(EventActivity::where('event_id', $event->id)->where('type', 'invitation_cover_removed')->count())->toBe(1);
    });

    it('answers 409 when the template has no cover photo field', function () {
        edInstall(edBuilder('sinfoto', 'free')->json('editable.json', fn ($e) => [...$e, 'fields' => array_values(array_filter($e['fields'], fn ($f) => $f['type'] !== 'image'))]));
        [$event, $m] = edEvent(['template_id' => edTemplateId('sinfoto')]);

        edUpload($this, $event, $m['owner'], edJpeg())->assertConflict()->assertJson(['code' => 'package_no_cover_field']);
    });

    it('closes the endpoint to a viewer and a stranger, and limits uploads to 10 a minute', function () {
        [$event, $m] = edPhotoEvent();

        edUpload($this, $event, $m['viewer'], edJpeg())->assertForbidden();
        edUpload($this, $event, $m['stranger'], edJpeg())->assertNotFound();
        $this->actingAs($m['viewer'])->deleteJson(edUrl($event, 'cover-photo'))->assertForbidden();
        for ($i = 0; $i < 10; $i++) {
            edUpload($this, $event, $m['owner'], edJpeg(700, 900))->assertCreated();
        }
        edUpload($this, $event, $m['owner'], edJpeg(700, 900))->assertTooManyRequests();
    });

    it('serves an upload only from the folder of its own event', function () {
        [$event, $m] = edPhotoEvent();
        $ulid = strtolower((string) Str::ulid());
        $event->update(['status' => 'published', 'template_config' => ['sections' => [['type' => 'cover', 'settings' => ['image' => "upload:images/event/999999/cover/{$ulid}-700x900.webp"]]]]]);

        $cover = $this->getJson('/api/v1/app/invitation/xv-de-valentina/ED1234')->assertOk()->json('data.sections.0.data');
        expect($cover['image'])->toBeNull();

        $event->update(['template_config' => ['sections' => [['type' => 'cover', 'settings' => ['image' => 'upload:../../etc/passwd']]]]]);
        expect($this->getJson('/api/v1/app/invitation/xv-de-valentina/ED1234')->json('data.sections.0.data.image'))->toBeNull();
    });

    it('lets the config validator accept upload: only for the event it is checking', function () {
        $package = new TemplatePackage(['editable' => ['fields' => [['id' => 'p', 'type' => 'image', 'bind' => ['section' => 'cover', 'setting' => 'image', 'focus' => 'focus'], 'plan' => 'free']]]]);
        $ulid = strtolower((string) Str::ulid());
        $config = fn (int $id) => ['sections' => [['type' => 'cover', 'settings' => ['image' => "upload:images/event/{$id}/cover/{$ulid}-700x900.webp"]]]];
        $validator = new EventTemplateConfigValidator;

        $validator->validate($config(7), $package, true, 7);
        foreach ([[$config(8), 7], [$config(7), null]] as [$bad, $id]) {
            expect(fn () => $validator->validate($bad, $package, true, $id))->toThrow(ValidationException::class);
        }
        foreach (['upload:images/event/7/cover/x.webp', 'upload:images/event/7/cover/' . $ulid . '-700x900.php', 'https://evil.test/a.jpg', '//evil.test/a.jpg'] as $value) {
            $c = ['sections' => [['type' => 'cover', 'settings' => ['image' => $value]]]];
            expect(fn () => $validator->validate($c, $package, true, 7))->toThrow(ValidationException::class);
        }
    });
});

describe('POST invitation/preview', function () {
    function edPreview($test, Events $event, User $user, array $body = [])
    {
        return $test->actingAs($user)->postJson(edUrl($event, 'preview'), $body);
    }

    it('has the very shape of the public invitation and draws the real event', function () {
        edInstall(edBuilder('festival', 'free'));
        [$event, $m] = edEvent(['template_id' => edTemplateId('festival'), 'template_config' => ['theme' => ['preset' => 'rosa']]]);
        $this->actingAs($m['owner'])->postJson("/api/v1/app/event/{$event->id}/publish")->assertOk();
        $public = $this->getJson('/api/v1/app/invitation/xv-de-valentina/ED1234')->assertOk()->json('data');

        $preview = edPreview($this, $event, $m['owner'])->assertOk()->json('data');

        expect(array_keys($preview))->toBe(array_keys($public))
            ->and($preview['event'])->toBe($public['event'])
            ->and($preview['theme'])->toBe($public['theme'])
            ->and(array_column($preview['sections'], 'type'))->toBe(array_column($public['sections'], 'type'))
            ->and($preview['branding'])->toBe($public['branding'])
            ->and($preview['guest']['name'])->toBe('Invitada/o');
    });

    it('gives the sample guest a pending RSVP status and no companions', function () {
        edInstall(edBuilder('festival', 'free'));
        [$event, $m] = edEvent(['template_id' => edTemplateId('festival'), 'template_config' => ['theme' => ['preset' => 'rosa']]]);

        $preview = edPreview($this, $event, $m['owner'])->assertOk()->json('data');

        $rsvp = collect($preview['sections'])->firstWhere('type', 'rsvp');
        expect($rsvp)->not->toBeNull()
            ->and($rsvp['data']['guest']['status'])->toBe('pending')
            ->and($rsvp['data']['companions'])->toBe([]);
    });

    it('shows unsaved changes and writes nothing', function () {
        edInstall(edBuilder('festival', 'free'));
        [$event, $m] = edEvent(['template_id' => edTemplateId('festival')]);
        $activity = EventActivity::count();

        $res = edPreview($this, $event, $m['owner'], ['fields' => ['look_preset' => 'rosa', 'cover_text' => ['es' => 'Frase nueva'], 'color_primary' => '#123456'], 'locale' => 'es'])->assertOk();

        expect($res->json('data.theme.preset'))->toBe('rosa')->and($res->json('data.theme.colors.primary'))->toBe('#123456')
            ->and($res->json('data.sections.0.text'))->toBe('Frase nueva')
            ->and($event->fresh()->template_config)->toBeNull()->and(EventActivity::count())->toBe($activity);
        edPreview($this, $event, $m['owner'], ['preset' => 'oliva'])->assertOk()->assertJsonPath('data.theme.preset', 'oliva');
        edPreview($this, $event, $m['owner'], ['locale' => 'en'])->assertOk()->assertJsonPath('data.locale', 'en');
    });

    it('previews another template, premium ones included, with the event data', function () {
        edInstall(edBuilder('festival', 'free'));
        edInstall(edBuilder('galeria', 'premium'));
        [$event, $m] = edEvent(['template_id' => edTemplateId('festival'), 'template_config' => ['theme' => ['preset' => 'rosa'], 'sections' => [['type' => 'cover', 'text' => ['es' => 'Mi frase']]]]]);

        $res = edPreview($this, $event, $m['owner'], ['template_id' => edTemplateId('galeria')])->assertOk();

        expect($res->json('data.theme.name'))->toBe('galeria')->and($res->json('data.theme.package.slug'))->toBe('galeria')
            ->and($res->json('data.theme.preset'))->toBe('salvia')
            ->and($res->json('data.sections.0.text'))->toBe('Mi frase')
            ->and($res->json('data.event.name'))->toBe('Los XV de Valentina')
            ->and($event->fresh()->template_id)->toBe(edTemplateId('festival'));
        // Even a template that is not live for the event does not draw the free one instead.
        edPlan($event, 'basic');
        edPreview($this, $event, $m['owner'], ['template_id' => edTemplateId('galeria')])->assertOk()->assertJsonPath('data.theme.name', 'galeria');
    });

    it('draws the Save the date and the no photo state, with a sample guest and no real people', function () {
        edInstall(edBuilder('festival', 'free'));
        [$event, $m] = edEvent(['template_id' => edTemplateId('festival'), 'template_config' => ['sections' => [['type' => 'cover', 'settings' => ['image' => '/storage/x.jpg']]]]]);
        $guest = Guest::where('event_id', $event->id)->first();
        Guest::factory()->create(['event_id' => $event->id, 'parent_id' => $guest->id, 'name' => 'Acompanante Real']);
        Guest::factory()->create(['event_id' => $event->id, 'parent_id' => null, 'name' => 'Otra Familia']);

        $full = edPreview($this, $event, $m['owner'])->assertOk()->json('data');
        $nophoto = edPreview($this, $event, $m['owner'], ['state' => 'no_photo'])->assertOk()->json('data');
        $std = edPreview($this, $event, $m['owner'], ['state' => 'save_the_date'])->assertOk()->json('data');

        expect($full['sections'][0]['data']['image'])->toBe('/storage/x.jpg')->and($nophoto['sections'][0]['data']['image'])->toBeNull()
            ->and($std['stage'])->toBe('save_the_date')->and(array_column($std['sections'], 'type'))->toContain('cover')->not->toContain('rsvp')
            ->and(json_encode($full))->not->toContain('Acompanante Real')->not->toContain('Otra Familia');
        $rsvp = collect($full['sections'])->firstWhere('type', 'rsvp');
        expect($rsvp['data']['companions'])->toBe([])->and($rsvp['data']['guest']['id'])->toBeNull();
    });

    it('answers the same codes as the writes, and 404/403 for templates', function () {
        edInstall(edBuilder('festival', 'free'));
        edInstall(edBuilder('secreta', 'free')->json('manifest.json', fn ($mm) => [...$mm, 'visibility' => 'private']));
        [$event, $m] = edEvent(['template_id' => edTemplateId('festival')]);

        $r = edPreview($this, $event, $m['owner'], ['fields' => ['color_primary' => 'rojo']])->assertUnprocessable();
        expect($r->json('errors')['fields.color_primary'][0])->toBe('invalid_color');
        $r = edPreview($this, $event, $m['owner'], ['fields' => ['cover_text' => ['es' => str_repeat('x', 99)]]])->assertUnprocessable();
        expect($r->json('errors')['fields.cover_text.es'][0])->toBe('too_long');
        edPreview($this, $event, $m['owner'], ['fields' => ['nope' => 1]])->assertNotFound()->assertJson(['code' => 'field_not_found']);
        edPreview($this, $event, $m['owner'], ['preset' => 'zzz'])->assertUnprocessable()->assertJsonPath('errors.preset.0', 'invalid_choice');
        edPreview($this, $event, $m['owner'], ['template_id' => 999999])->assertNotFound()->assertJson(['code' => 'template_not_found']);
        edPreview($this, $event, $m['owner'], ['template_id' => edTemplateId('secreta')])->assertForbidden()->assertJson(['code' => 'template_not_available']);
        edPreview($this, $event, $m['owner'], ['locale' => 'fr'])->assertUnprocessable()->assertJsonPath('errors.locale.0', 'invalid');
        edPreview($this, $event, $m['owner'], ['state' => 'x'])->assertUnprocessable()->assertJsonPath('errors.state.0', 'invalid');
        edPreview($this, $event, $m['stranger'])->assertNotFound();
        edPreview($this, $event, $m['viewer'])->assertForbidden();
    });

    it('limits previews to 30 a minute', function () {
        edInstall(edBuilder('festival', 'free'));
        [$event, $m] = edEvent(['template_id' => edTemplateId('festival')]);
        $this->actingAs($m['owner']);

        for ($i = 0; $i < 30; $i++) {
            $this->postJson(edUrl($event, 'preview'))->assertOk();
        }
        $this->postJson(edUrl($event, 'preview'))->assertTooManyRequests();
    });
});

describe('selector thumbnails (D-5)', function () {
    it('turns preview/mobile.png into a light public WebP 390x844 and links the templates', function () {
        $package = edInstall(edBuilder('festival', 'free'));
        $paths = InvitationTemplate::whereIn('slug', ['festival-wedding', 'festival-quinceanera'])->pluck('preview_image')->unique();

        expect($paths)->toHaveCount(1)->and($paths[0])->toStartWith('previews/festival-1.0.0-')->and($paths[0])->toEndWith('.webp');
        $bytes = Storage::disk('template-packages')->get($paths[0]);
        $size = getimagesizefromstring($bytes);
        expect([$size[0], $size[1], $size['mime']])->toBe([390, 844, 'image/webp'])
            ->and(strlen($bytes))->toBeLessThan(80 * 1024)
            ->and(strlen($bytes))->toBeLessThan(strlen((string) file_get_contents(__DIR__ . '/../Fixtures/TemplatePackage/good/preview/mobile.png')) + 1);

        [$event, $m] = edEvent(['template_id' => edTemplateId('festival')]);
        $list = $this->actingAs($m['owner'])->getJson(edUrl($event, 'templates'))->assertOk()->json('data');
        expect(collect($list)->firstWhere('slug', 'festival-quinceanera')['previewImage'])->toContain($paths[0]);
    });

    it('downsizes a 780x1688 capture', function () {
        $png = (function () {
            $im = imagecreatetruecolor(780, 1688);
            imagefill($im, 0, 0, imagecolorallocate($im, 200, 180, 160));
            ob_start();
            imagepng($im);

            return (string) ob_get_clean();
        })();
        edInstall(edBuilder('festival', 'free')->with('preview/mobile.png', $png));

        $path = InvitationTemplate::where('slug', 'festival-quinceanera')->value('preview_image');

        expect(getimagesizefromstring(Storage::disk('template-packages')->get($path))[1])->toBe(844);
    });

    it('regenerates a missing thumbnail from the original zip, idempotently', function () {
        $builder = edBuilder('festival', 'free');
        edInstall($builder);
        $path = InvitationTemplate::where('slug', 'festival-quinceanera')->value('preview_image');
        Storage::disk('template-packages')->delete($path);
        InvitationTemplate::query()->update(['preview_image' => null]);

        $this->artisan('templates:thumbnails')->assertFailed();
        expect(InvitationTemplate::where('slug', 'festival-quinceanera')->value('preview_image'))->toBeNull();

        $this->artisan('templates:thumbnails', ['--zip' => $builder->zip()])->assertSuccessful();
        expect(InvitationTemplate::where('slug', 'festival-quinceanera')->value('preview_image'))->toBe($path)
            ->and(Storage::disk('template-packages')->exists($path))->toBeTrue();

        $bytes = Storage::disk('template-packages')->get($path);
        $this->artisan('templates:thumbnails', ['--zip' => $builder->zip()])->assertSuccessful();
        $this->artisan('templates:thumbnails')->assertSuccessful();
        expect(Storage::disk('template-packages')->get($path))->toBe($bytes);

        // A zip that is not an installed package is refused.
        $other = edBuilder('otra', 'free')->json('manifest.json', fn ($mm) => [...$mm, 'slug' => 'otra']);
        $this->artisan('templates:thumbnails', ['--zip' => $other->zip()])->assertFailed();
    });
});

describe('wave 9 follow-up', function () {
    it('reports locked-module sections as not enabled and lets them travel back disabled', function () {
        edInstall(edBuilder());
        [$event, $m] = edEvent(['template_id' => edTemplateId('festival'), 'template_config' => ['sections' => [['type' => 'gallery', 'enabled' => true, 'order' => 6]]]]);

        $sections = $this->actingAs($m['owner'])->getJson(edUrl($event))->assertOk()->json('data.sections');
        $gallery = collect($sections)->firstWhere('type', 'gallery');
        expect($gallery)->toMatchArray(['enabled' => false, 'moduleState' => 'locked']);

        // The very state the app got can be sent back (to reorder) without a 422.
        $body = array_map(fn ($s) => ['type' => $s['type'], 'enabled' => $s['enabled'], 'order' => $s['order']], $sections);
        $this->patchJson(edUrl($event, 'sections'), ['sections' => $body])->assertOk();
        $this->patchJson(edUrl($event, 'sections'), ['sections' => [['type' => 'gallery', 'enabled' => true]]])->assertUnprocessable();
    });

    it('serves the selector thumbnail through /template-assets with the safe headers', function () {
        edInstall(edBuilder('festival', 'free'));
        $path = InvitationTemplate::where('slug', 'festival-quinceanera')->value('preview_image');

        $r = $this->get("/template-assets/{$path}", ['Origin' => 'https://evil.example'])->assertOk();
        expect($r->headers->get('Content-Type'))->toBe('image/webp')
            ->and($r->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($r->headers->get('Content-Security-Policy'))->toContain('sandbox')
            ->and($r->headers->get('Cross-Origin-Resource-Policy'))->toBe('cross-origin')
            ->and($r->headers->get('Cache-Control'))->toContain('immutable')
            ->and($r->headers->get('Access-Control-Allow-Origin'))->toBeNull();

        foreach (['previews/x.webp', 'previews/festival-1.0.0-zzzzzzzzzzzzzzzz.webp', 'previews/../festival-1.0.0-0123456789abcdef.webp', 'previews/festival-1.0.0-0123456789abcdef.png', 'previews//a-1.0.0-0123456789abcdef.webp'] as $bad) {
            $this->get("/template-assets/{$bad}")->assertNotFound();
        }
    });
});

describe('image zones', function () {
    /** A free package with the cover photo plus an image zone in the closing (paid) and in locations (free), as a template would declare them. */
    function zoneBuilder(string $slug = 'zonas', string $closingPlan = 'full'): PackageBuilder
    {
        return edBuilder($slug, 'free')
            ->with('sections/locations.html', '<div class="pkg-loc"><q-section-image class="pkg-pic" aspect="16:10" fit="cover"></q-section-image><q-locations></q-locations></div>')
            ->with('sections/closing.html', '<div class="pkg-closing"><q-section-image aspect="21:9"></q-section-image><h2>{{ section.title }}</h2></div>')
            ->json('editable.json', function (array $e) use ($closingPlan) {
                $e['fields'][] = ['id' => 'closing_image', 'type' => 'image', 'group' => 'cover', 'label' => ['es' => 'Foto del cierre', 'en' => 'Closing photo'], 'bind' => ['section' => 'closing', 'setting' => 'image', 'focus' => 'focus'], 'minWidth' => 600, 'aspect' => '21:9', 'plan' => $closingPlan, 'alt' => ['es' => 'Una pareja bailando', 'en' => 'A couple dancing']];
                $e['fields'][] = ['id' => 'locations_image', 'type' => 'image', 'group' => 'cover', 'label' => ['es' => 'Foto del lugar', 'en' => 'Venue photo'], 'bind' => ['section' => 'locations', 'setting' => 'image', 'focus' => 'focus'], 'minWidth' => 600, 'aspect' => '16:10', 'plan' => 'free', 'alt' => ['es' => '', 'en' => '']];

                return $e;
            });
    }

    function zoneEvent(string $plan = 'premium', string $closingPlan = 'full'): array
    {
        edInstall(zoneBuilder('zonas', $closingPlan));
        [$event, $m] = edEvent(['template_id' => edTemplateId('zonas')]);
        edPlan($event, $plan);
        EventLocation::create(['event_id' => $event->id, 'type' => 'reception', 'name' => 'Salón Jardín', 'address' => '1 Main St', 'city' => 'Miami', 'country' => 'US']);

        return [$event, $m];
    }

    function zoneUpload($test, Events $event, User $user, string $field, string|UploadedFile $file, array $extra = [])
    {
        $upload = $file instanceof UploadedFile ? $file : UploadedFile::fake()->createWithContent('foto.jpg', $file);

        return $test->actingAs($user)->post(edUrl($event, "fields/{$field}/image"), ['photo' => $upload] + $extra, ['Accept' => 'application/json']);
    }

    /** The section of a public payload; fails loudly when it is not there, so a missing section never passes as "no image". */
    function zoneSection(array $payload, string $type): array
    {
        $section = collect($payload['data']['sections'])->firstWhere('type', $type);
        expect($section)->not->toBeNull("section {$type} is not in the invitation");

        return $section;
    }

    /** Turns the closing and locations on for the event, keeping what its config holds. */
    function zoneEnable(Events $event): void
    {
        $config = $event->fresh()->template_config ?? [];
        $sections = collect($config['sections'] ?? [])->keyBy('type');
        foreach (['locations' => 2, 'closing' => 9] as $type => $order) {
            $sections[$type] = ($sections[$type] ?? ['type' => $type]) + ['enabled' => true, 'order' => $order];
        }
        $config['sections'] = $sections->values()->all();
        $event->update(['template_config' => $config]);
    }

    it('stores the picture in the folder of its zone and serves it in the editor', function () {
        [$event, $m] = zoneEvent();

        $res = zoneUpload($this, $event, $m['owner'], 'closing_image', edJpeg(1800, 900), ['focus' => '30% 60%'])->assertCreated();

        $data = $res->json('data');
        expect($data)->toMatchArray(['width' => 1440, 'height' => 720, 'focus' => '30% 60%'])
            ->and($data['url'])->toContain("images/event/{$event->id}/zone-closing/")->and($data['thumbUrl'])->toEndWith('-thumb.webp');
        $settings = collect($event->fresh()->template_config['sections'])->firstWhere('type', 'closing')['settings'];
        expect($settings['image'])->toStartWith("upload:images/event/{$event->id}/zone-closing/")->and($settings['focus'])->toBe('30% 60%');
        $path = substr($settings['image'], 7);
        $disk = Storage::disk('event-media');
        // No share image: only the cover has one.
        expect($disk->exists($path))->toBeTrue()->and($disk->exists(CoverPhoto::thumbPath($path)))->toBeTrue()->and($disk->exists(CoverPhoto::sharePath($path)))->toBeFalse();

        $fields = collect($this->getJson(edUrl($event))->json('data.fields'));
        expect($fields->firstWhere('id', 'closing_image')['value'])->toMatchArray(['width' => 1440, 'height' => 720, 'focus' => '30% 60%'])
            // Z3: the cover field no longer shows the zone's picture, nor the other way round.
            ->and($fields->firstWhere('id', 'cover_photo')['value'])->toBeNull()
            ->and($fields->firstWhere('id', 'locations_image')['value'])->toBeNull();
        expect(EventActivity::where('event_id', $event->id)->where('type', 'invitation_image_uploaded')->first()->payload)->toBe(['field' => 'closing_image']);
    });

    it('drops EXIF and GPS from a zone picture and applies the orientation', function () {
        [$event, $m] = zoneEvent();
        $source = edJpeg(900, 1200, 6, true);
        expect(@exif_read_data('data://image/jpeg;base64,' . base64_encode($source), 'GPS', true)['GPS'] ?? null)->not->toBeNull('the fixture must carry GPS');

        $res = zoneUpload($this, $event, $m['owner'], 'locations_image', $source)->assertCreated();

        expect($res->json('data.width'))->toBe(1200)->and($res->json('data.height'))->toBe(900);
        $bytes = Storage::disk('event-media')->get(substr(collect($event->fresh()->template_config['sections'])->firstWhere('type', 'locations')['settings']['image'], 7));
        expect(str_contains($bytes, 'EXIF'))->toBeFalse()->and(str_contains($bytes, 'GPS'))->toBeFalse()->and(str_contains($bytes, 'Exif'))->toBeFalse()
            ->and(substr($bytes, 8, 4))->toBe('WEBP');
        // A trailer after the image does not survive either.
        $trailer = edJpeg(900, 700) . 'SECRET-TRAILER-LAT-25.7617';
        $other = zoneUpload($this, $event, $m['owner'], 'locations_image', $trailer)->assertCreated();
        $bytes = Storage::disk('event-media')->get(substr(collect($event->fresh()->template_config['sections'])->firstWhere('type', 'locations')['settings']['image'], 7));
        expect(str_contains($bytes, 'SECRET-TRAILER'))->toBeFalse()->and($other->json('data.width'))->toBe(900);
    });

    it('answers the same code per kind of bad file as the cover and keeps the picture she had', function () {
        [$event, $m] = zoneEvent();
        zoneUpload($this, $event, $m['owner'], 'closing_image', edJpeg())->assertCreated();
        $before = $event->fresh()->template_config;
        $tooWide = (function () {
            $im = imagecreatetruecolor(8001, 10);
            ob_start();
            imagepng($im, null, 9);

            return (string) ob_get_clean();
        })();
        $tiny = (function () {
            $im = imagecreatetruecolor(100, 100);
            ob_start();
            imagejpeg($im);

            return (string) ob_get_clean();
        })();
        $cases = [
            'not_an_image' => UploadedFile::fake()->createWithContent('a.jpg', 'texto'),
            'unsupported_type' => UploadedFile::fake()->createWithContent('a.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            'corrupt' => UploadedFile::fake()->createWithContent('a.jpg', "\xFF\xD8\xFF\xE0" . random_bytes(300)),
            'too_many_pixels' => UploadedFile::fake()->createWithContent('a.png', $tooWide),
            'too_small' => UploadedFile::fake()->createWithContent('a.jpg', $tiny),
            'too_large' => UploadedFile::fake()->create('big.jpg', 11 * 1024, 'image/jpeg'),
            'required' => UploadedFile::fake()->create('empty.jpg', 0),
        ];
        foreach ($cases as $code => $file) {
            $res = zoneUpload($this, $event, $m['owner'], 'closing_image', $file)->assertUnprocessable();
            expect($res->json('errors.photo.0'))->toBe($code, json_encode($res->json()));
        }
        $this->actingAs($m['owner'])->post(edUrl($event, 'fields/closing_image/image'), [], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonPath('errors.photo.0', 'required');
        zoneUpload($this, $event, $m['owner'], 'closing_image', edJpeg(), ['focus' => 'arriba'])->assertUnprocessable()->assertJsonPath('errors.focus.0', 'invalid_focus');
        expect($event->fresh()->template_config)->toEqual($before)->and(Storage::disk('event-media')->allFiles())->toHaveCount(2);
    });

    it('refuses a field that is unknown (404) or not a zone: the cover, a text, a color (409)', function () {
        [$event, $m] = zoneEvent();

        zoneUpload($this, $event, $m['owner'], 'nope', edJpeg())->assertNotFound()->assertJson(['code' => 'field_not_found']);
        foreach (['cover_photo', 'cover_text', 'color_primary', 'ornaments'] as $id) {
            zoneUpload($this, $event, $m['owner'], $id, edJpeg())->assertConflict()->assertJson(['code' => 'package_no_image_field']);
            $this->deleteJson(edUrl($event, "fields/{$id}/image"))->assertConflict();
        }
        $this->deleteJson(edUrl($event, 'fields/nope/image'))->assertNotFound();
        expect(Storage::disk('event-media')->allFiles())->toBe([]);
    });

    it('asks for the full event on a paid zone and lets the free one through', function () {
        [$event, $m] = zoneEvent('basic');

        zoneUpload($this, $event, $m['owner'], 'closing_image', edJpeg())->assertForbidden()->assertJson(['code' => 'plan_required']);
        zoneUpload($this, $event, $m['owner'], 'locations_image', edJpeg())->assertCreated();
        expect(Storage::disk('event-media')->allFiles())->toHaveCount(2);
    });

    it('is closed to a viewer, a stranger, an archived event and a guest, and limits uploads with the cover limiter', function () {
        [$event, $m] = zoneEvent();

        zoneUpload($this, $event, $m['viewer'], 'closing_image', edJpeg())->assertForbidden();
        zoneUpload($this, $event, $m['stranger'], 'closing_image', edJpeg())->assertNotFound();
        $this->actingAs($m['viewer'])->deleteJson(edUrl($event, 'fields/closing_image/image'))->assertForbidden();
        $this->actingAs($m['stranger'])->deleteJson(edUrl($event, 'fields/closing_image/image'))->assertNotFound();
        auth()->forgetGuards();
        $this->post(edUrl($event, 'fields/closing_image/image'), [], ['Accept' => 'application/json'])->assertUnauthorized();
        $event->update(['status' => 'archived']);
        zoneUpload($this, $event, $m['owner'], 'closing_image', edJpeg())->assertUnprocessable()->assertJsonPath('errors.status.0', 'event_archived');
        $event->update(['status' => 'draft']);
        $created = 0;
        $limited = false;
        for ($i = 0; $i < 14 && ! $limited; $i++) {
            $res = zoneUpload($this, $event, $m['owner'], 'locations_image', edJpeg(700, 900));
            $limited = $res->status() === 429;
            $created += $res->status() === 201 ? 1 : 0;
        }
        // Ten a minute per user, counted with the cover: the cover endpoint is closed by the same counter.
        expect($limited)->toBeTrue()->and($created)->toBeLessThan(10)->and($created)->toBeGreaterThan(4);
        edUpload($this, $event, $m['owner'], edJpeg(700, 900))->assertTooManyRequests();
    });

    it('never lets one event write or remove the picture of another (IDOR by field)', function () {
        [$mine, $m] = zoneEvent();
        [$theirs, $other] = edEvent(['template_id' => edTemplateId('zonas'), 'custom_url_slug' => 'otro-evento']);
        edPlan($theirs, 'premium');
        zoneUpload($this, $theirs, $other['owner'], 'closing_image', edJpeg())->assertCreated();
        $theirPath = substr(collect($theirs->fresh()->template_config['sections'])->firstWhere('type', 'closing')['settings']['image'], 7);

        // My owner on THEIR event: no access, nothing changes.
        zoneUpload($this, $theirs, $m['owner'], 'closing_image', edJpeg())->assertNotFound();
        $this->actingAs($m['owner'])->deleteJson(edUrl($theirs, 'fields/closing_image/image'))->assertNotFound();
        expect(Storage::disk('event-media')->exists($theirPath))->toBeTrue();

        // A path of the other event written by hand is refused by the config validator, wherever it is put.
        $ulid = strtolower((string) Str::ulid());
        $package = new TemplatePackage(['editable' => ['fields' => [['id' => 'z', 'type' => 'image', 'bind' => ['section' => 'closing', 'setting' => 'image', 'focus' => 'focus'], 'plan' => 'free']]]]);
        $validator = new EventTemplateConfigValidator;
        $foreign = ['sections' => [['type' => 'closing', 'settings' => ['image' => "upload:images/event/{$theirs->id}/zone-closing/{$ulid}-700x900.webp"]]]];
        expect(fn () => $validator->validate($foreign, $package, true, $mine->id))->toThrow(ValidationException::class);
        // The folder of one zone cannot be bound to another section, nor the cover's folder to a zone.
        foreach (["upload:images/event/{$mine->id}/zone-locations/{$ulid}-700x900.webp", "upload:images/event/{$mine->id}/cover/{$ulid}-700x900.webp"] as $value) {
            $c = ['sections' => [['type' => 'closing', 'settings' => ['image' => $value]]]];
            expect(fn () => $validator->validate($c, $package, true, $mine->id))->toThrow(ValidationException::class);
        }
        $own = ['sections' => [['type' => 'closing', 'settings' => ['image' => "upload:images/event/{$mine->id}/zone-closing/{$ulid}-700x900.webp"]]]];
        $validator->validate($own, $package, true, $mine->id);
        // No folder outside the two zones.
        foreach (['zone-rsvp', 'zone-dress_code', 'zone-', 'zones-closing', 'zone-closing/../cover'] as $folder) {
            expect(CoverPhoto::pathFor("upload:images/event/{$mine->id}/{$folder}/{$ulid}-700x900.webp", $mine->id))->toBeNull();
        }
    });

    it('only accepts an image setting for a field the package declares, and not through the PATCH of fields', function () {
        [$event, $m] = zoneEvent();
        $this->actingAs($m['owner']);

        // The picture comes only through the upload endpoint: the PATCH takes the focus and nothing else.
        $this->patchJson(edUrl($event), ['fields' => ['closing_image' => ['image' => 'upload:images/event/1/zone-closing/x.webp']]])->assertUnprocessable();
        $this->patchJson(edUrl($event), ['fields' => ['closing_image' => ['focus' => '10% 20%']]])->assertOk();
        expect(collect($event->fresh()->template_config['sections'])->firstWhere('type', 'closing')['settings'])->toBe(['focus' => '10% 20%']);
    });

    it('replaces the picture, deleting the old files only after the new one is saved, and removes it idempotently', function () {
        [$event, $m] = zoneEvent();
        zoneUpload($this, $event, $m['owner'], 'closing_image', edJpeg(), ['focus' => '10% 10%'])->assertCreated();
        $first = substr(collect($event->fresh()->template_config['sections'])->firstWhere('type', 'closing')['settings']['image'], 7);

        zoneUpload($this, $event, $m['owner'], 'closing_image', edJpeg(800, 1000))->assertCreated();
        $second = substr(collect($event->fresh()->template_config['sections'])->firstWhere('type', 'closing')['settings']['image'], 7);
        expect($second)->not->toBe($first)->and(Storage::disk('event-media')->exists($first))->toBeFalse()->and(Storage::disk('event-media')->allFiles())->toHaveCount(2);

        $this->actingAs($m['owner'])->deleteJson(edUrl($event, 'fields/closing_image/image'))->assertOk()->assertExactJson(['data' => null]);
        $this->deleteJson(edUrl($event, 'fields/closing_image/image'))->assertOk();
        expect($event->fresh()->template_config)->toBeNull()->and(Storage::disk('event-media')->allFiles())->toBe([])
            ->and(EventActivity::where('event_id', $event->id)->where('type', 'invitation_image_removed')->count())->toBe(1);
    });

    it('keeps the cover and the zones apart: removing one leaves the others', function () {
        [$event, $m] = zoneEvent();
        edUpload($this, $event, $m['owner'], edJpeg())->assertCreated();
        zoneUpload($this, $event, $m['owner'], 'closing_image', edJpeg())->assertCreated();
        zoneUpload($this, $event, $m['owner'], 'locations_image', edJpeg())->assertCreated();

        $this->deleteJson(edUrl($event, 'fields/closing_image/image'))->assertOk();
        $this->deleteJson(edUrl($event, 'cover-photo'))->assertOk();

        $sections = collect($event->fresh()->template_config['sections']);
        expect($sections->pluck('type')->all())->toBe(['locations'])
            ->and(Storage::disk('event-media')->allFiles())->toHaveCount(2);
    });

    it('leaves nothing behind when the config grows too big', function () {
        [$event, $m] = zoneEvent();
        $event->update(['template_config' => ['sections' => [['type' => 'cover', 'title' => ['es' => str_repeat('a', 16300)]]]]]);

        zoneUpload($this, $event, $m['owner'], 'closing_image', edJpeg())->assertUnprocessable()->assertJsonPath('errors.fields.0', 'config_too_large');

        expect(Storage::disk('event-media')->allFiles())->toBe([]);
    });

    it('carries section.image in the public invitation only when the template declares the zone and the plan allows it', function () {
        [$event, $m] = zoneEvent();
        zoneUpload($this, $event, $m['owner'], 'closing_image', edJpeg(1800, 900), ['focus' => '30% 60%'])->assertCreated();
        zoneUpload($this, $event, $m['owner'], 'locations_image', edJpeg(1200, 800))->assertCreated();
        zoneEnable($event);
        $this->postJson("/api/v1/app/event/{$event->id}/publish")->assertOk();

        $payload = $this->getJson('/api/v1/app/invitation/xv-de-valentina/ED1234')->assertOk()->json();
        $closing = zoneSection($payload, 'closing');
        expect($closing['image'])->toMatchArray(['width' => 1440, 'height' => 720, 'focus' => '30% 60%', 'alt' => 'Una pareja bailando'])
            ->and($closing['image']['url'])->toContain("images/event/{$event->id}/zone-closing/")
            ->and(array_keys($closing['image']))->toBe(['url', 'thumbUrl', 'width', 'height', 'focus', 'alt'])
            // The path of the file never travels in a setting.
            ->and(json_encode($payload))->not->toContain('upload:')
            ->and(zoneSection($payload, 'cover')['data']['image'])->toBeNull()
            ->and(zoneSection($payload, 'rsvp'))->not->toHaveKey('image');
        $locations = zoneSection($payload, 'locations');
        expect($locations['image']['alt'])->toBe('')->and($locations['image']['focus'])->toBeNull();

        // English reads the English text.
        expect(zoneSection($this->getJson('/api/v1/app/invitation/xv-de-valentina/ED1234?lang=en')->json(), 'closing')['image']['alt'])->toBe('A couple dancing');
    });

    it('hides a paid zone when the event goes back to basic, and shows nothing for a missing or foreign file', function () {
        [$event, $m] = zoneEvent();
        zoneUpload($this, $event, $m['owner'], 'closing_image', edJpeg())->assertCreated();
        zoneEnable($event);
        $this->postJson("/api/v1/app/event/{$event->id}/publish")->assertOk();
        expect(zoneSection($this->getJson('/api/v1/app/invitation/xv-de-valentina/ED1234')->json(), 'closing'))->toHaveKey('image');

        // A refund puts the draft back on basic: the paid field is not shown (the event is not fixed to a version).
        $event->refresh()->update(['template_version_id' => null]);
        edPlan($event, 'basic');
        expect(zoneSection($this->getJson('/api/v1/app/invitation/xv-de-valentina/ED1234')->json(), 'closing'))->not->toHaveKey('image');

        // A path of another event, a malformed one, or the cover's folder on a zone: no image, no error.
        $ulid = strtolower((string) Str::ulid());
        foreach (["upload:images/event/999999/zone-closing/{$ulid}-700x900.webp", 'upload:../../etc/passwd', "upload:images/event/{$event->id}/cover/{$ulid}-700x900.webp", 'https://evil.test/a.jpg', 12] as $value) {
            $event->update(['template_config' => ['sections' => [['type' => 'closing', 'enabled' => true, 'order' => 9, 'settings' => ['image' => $value]]]]]);
            edPlan($event, 'premium');
            expect(zoneSection($this->getJson('/api/v1/app/invitation/xv-de-valentina/ED1234')->json(), 'closing'))->not->toHaveKey('image');
        }
    });

    it('does not show a stored picture when the template has no field for the zone, and brings it back with one that has', function () {
        [$event, $m] = zoneEvent();
        zoneUpload($this, $event, $m['owner'], 'closing_image', edJpeg())->assertCreated();
        zoneEnable($event);
        edInstall(edBuilder('sinzona', 'free'));
        $event->update(['template_id' => edTemplateId('sinzona'), 'template_version_id' => null, 'status' => 'published']);

        expect(zoneSection($this->getJson('/api/v1/app/invitation/xv-de-valentina/ED1234')->json(), 'closing'))->not->toHaveKey('image');

        $event->update(['template_id' => edTemplateId('zonas')]);
        expect(zoneSection($this->getJson('/api/v1/app/invitation/xv-de-valentina/ED1234')->json(), 'closing'))->toHaveKey('image');
    });

    it('does not serve the invitation of an event that is not published', function () {
        [$event, $m] = zoneEvent();
        zoneUpload($this, $event, $m['owner'], 'closing_image', edJpeg())->assertCreated();
        auth()->forgetGuards();

        $this->getJson('/api/v1/app/invitation/xv-de-valentina/ED1234')->assertNotFound();
    });

    it('tells in the impact of a template change that the zone picture is kept, and whether the new one has the zone', function () {
        [$event, $m] = zoneEvent();
        edInstall(edBuilder('sinzona', 'free'));
        edInstall(zoneBuilder('otrazona'));
        zoneUpload($this, $event, $m['owner'], 'closing_image', edJpeg())->assertCreated();

        $without = $this->getJson(edUrl($event, 'templates/' . edTemplateId('sinzona') . '/impact'))->assertOk()->json('data.keeps');
        $with = $this->getJson(edUrl($event, 'templates/' . edTemplateId('otrazona') . '/impact'))->assertOk()->json('data.keeps');

        expect($without)->toContain(['kind' => 'section_image', 'section' => 'closing', 'editable' => false])
            ->and($with)->toContain(['kind' => 'section_image', 'section' => 'closing', 'editable' => true]);
    });

    it('deletes every file of an event, the cover and the zones, when the event is purged', function () {
        [$event, $m] = zoneEvent();
        edUpload($this, $event, $m['owner'], edJpeg())->assertCreated();
        zoneUpload($this, $event, $m['owner'], 'closing_image', edJpeg())->assertCreated();
        zoneUpload($this, $event, $m['owner'], 'locations_image', edJpeg())->assertCreated();
        [$other, $o] = edEvent(['template_id' => edTemplateId('zonas'), 'custom_url_slug' => 'otro']);
        zoneUpload($this, $other, $o['owner'], 'locations_image', edJpeg())->assertCreated();
        expect(Storage::disk('event-media')->allFiles())->not->toBe([]);

        app(EventPurger::class)->purge($event);

        $left = Storage::disk('event-media')->allFiles();
        expect($left)->not->toBe([])
            ->and(collect($left)->every(fn ($f) => str_contains($f, "images/event/{$other->id}/")))->toBeTrue();
    });
});

describe('template picker: tags, description and palettes', function () {
    it('reads tags and description from the row, the manifest description wins, HTML never leaves', function () {
        edInstall(edBuilder('festival', 'free'));
        [$event, $m] = edEvent(['template_id' => edTemplateId('festival')]);
        $classicId = InvitationTemplate::where('slug', '!=', 'festival-quinceanera')->where('event_type_id', $event->event_type_id)->value('id');
        InvitationTemplate::whereKey($classicId)->update([
            'tags' => json_encode(['elegante', '<b>floral</b>', '', 5]),
            'description' => json_encode(['es' => 'Clásica <script>x</script>', 'en' => null]),
        ]);
        InvitationTemplate::whereKey(edTemplateId('festival'))->update(['description' => json_encode(['es' => 'de la fila', 'en' => 'row'])]);

        $list = collect($this->actingAs($m['owner'])->getJson(edUrl($event, 'templates'))->assertOk()->json('data'));

        $classic = $list->firstWhere('id', $classicId);
        expect($classic['tags'])->toBe(['elegante', 'floral'])
            ->and($classic['description'])->toBe(['es' => 'Clásica x', 'en' => null])
            ->and($classic['presets'])->toBe([])
            ->and($list->firstWhere('slug', 'festival-quinceanera')['description']['es'])->not->toBe('de la fila');
    });

    it('lists the presets with key, name and palette next to the old fields', function () {
        edInstall(edBuilder('festival', 'free'));
        [$event, $m] = edEvent(['template_id' => edTemplateId('festival')]);

        $presets = $this->actingAs($m['owner'])->getJson(edUrl($event, 'templates'))->json('data.0.presets');

        expect($presets)->toHaveCount(3)
            ->and($presets[2])->toMatchArray(['value' => 'rosa', 'key' => 'rosa', 'name' => 'Rosa'])
            ->and($presets[2]['palette'])->toBe(['background' => '#fbf8f1', 'text' => '#34382c', 'accent' => '#8c8658'])
            ->and($presets[2]['colors'])->toBe(['#fbf8f1', '#8c8658', '#b5838d']);
    });
});

describe('PUT invitation/template with preset', function () {
    function tpPut($test, Events $event, User $user, array $body)
    {
        return $test->actingAs($user)->putJson(edUrl($event, 'template'), $body);
    }

    function tpPreset($test, Events $event, User $user): ?string
    {
        $fields = $test->actingAs($user)->getJson(edUrl($event))->assertOk()->json('data.fields');

        return collect($fields)->firstWhere('id', 'look_preset')['value'] ?? null;
    }

    it('chooses template and palette in one save, and GET invitation shows the palette', function () {
        edInstall(edBuilder('festival', 'free'));
        edInstall(edBuilder('galeria', 'free'));
        [$event, $m] = edEvent(['template_id' => edTemplateId('festival')]);

        $res = tpPut($this, $event, $m['owner'], ['template_id' => edTemplateId('galeria'), 'preset' => 'oliva'])->assertOk();

        expect($event->fresh()->template_id)->toBe(edTemplateId('galeria'))
            ->and($event->fresh()->template_config['theme']['preset'])->toBe('oliva')
            ->and($res->json('data.undo.templateId'))->toBe(edTemplateId('festival'))
            ->and(tpPreset($this, $event, $m['owner']))->toBe('oliva');
    });

    it('undoes a template change that carried a palette, restoring the old palette', function () {
        edInstall(edBuilder('festival', 'free'));
        edInstall(edBuilder('galeria', 'free'));
        [$event, $m] = edEvent(['template_id' => edTemplateId('festival')]);
        edPatch($this, $event, $m['owner'], ['look_preset' => 'rosa'])->assertOk();

        $res = tpPut($this, $event, $m['owner'], ['template_id' => edTemplateId('galeria'), 'preset' => 'oliva'])->assertOk();
        tpPut($this, $event, $m['owner'], ['template_id' => $res->json('data.undo.templateId'), 'undo' => true])->assertOk();

        expect($event->fresh()->template_id)->toBe(edTemplateId('festival'))
            ->and(tpPreset($this, $event, $m['owner']))->toBe('rosa');
    });

    it('saves only the palette when the template sent is the current one, and undo brings the old palette back', function () {
        edInstall(edBuilder('festival', 'free'));
        [$event, $m] = edEvent(['template_id' => edTemplateId('festival')]);
        edPatch($this, $event, $m['owner'], ['look_preset' => 'rosa', 'ornaments' => false])->assertOk();

        $res = tpPut($this, $event, $m['owner'], ['template_id' => edTemplateId('festival'), 'preset' => 'oliva'])->assertOk();

        expect(tpPreset($this, $event, $m['owner']))->toBe('oliva')
            ->and($event->fresh()->template_config['package']['options'])->toBe(['ornaments' => false])
            ->and($res->json('data.undo.templateId'))->toBe(edTemplateId('festival'))
            ->and($res->json('data.undo.preset'))->toBe('rosa');

        tpPut($this, $event, $m['owner'], ['template_id' => edTemplateId('festival'), 'preset' => $res->json('data.undo.preset'), 'undo' => true])->assertOk();
        expect(tpPreset($this, $event, $m['owner']))->toBe('rosa');
        expect(EventActivity::where('event_id', $event->id)->where('type', 'invitation_template_undone')->count())->toBe(1);
    });

    it('undo of a palette that was the template default clears the override (preset null)', function () {
        edInstall(edBuilder('festival', 'free'));
        [$event, $m] = edEvent(['template_id' => edTemplateId('festival')]);

        $res = tpPut($this, $event, $m['owner'], ['template_id' => edTemplateId('festival'), 'preset' => 'rosa'])->assertOk();
        expect($res->json('data.undo'))->toHaveKey('preset')->and($res->json('data.undo.preset'))->toBeNull();

        tpPut($this, $event, $m['owner'], ['template_id' => edTemplateId('festival'), 'preset' => null, 'undo' => true])->assertOk();
        expect($event->fresh()->template_config)->toBeNull();
    });

    it('does nothing and offers no undo when the palette is already the saved one', function () {
        edInstall(edBuilder('festival', 'free'));
        [$event, $m] = edEvent(['template_id' => edTemplateId('festival')]);
        edPatch($this, $event, $m['owner'], ['look_preset' => 'rosa'])->assertOk();

        $res = tpPut($this, $event, $m['owner'], ['template_id' => edTemplateId('festival'), 'preset' => 'rosa'])->assertOk();

        expect($res->json('data.undo'))->toBeNull()
            ->and(EventActivity::where('event_id', $event->id)->where('type', 'invitation_template_changed')->count())->toBe(0);
    });

    it('keeps the old behaviour without preset', function () {
        edInstall(edBuilder('festival', 'free'));
        [$event, $m] = edEvent(['template_id' => edTemplateId('festival')]);
        edPatch($this, $event, $m['owner'], ['look_preset' => 'rosa'])->assertOk();

        $res = tpPut($this, $event, $m['owner'], ['template_id' => edTemplateId('festival')])->assertOk();

        expect($res->json('data.undo'))->toBeNull()->and(tpPreset($this, $event, $m['owner']))->toBe('rosa');
    });

    it('refuses a palette the template does not have, or a malformed one, with 422 invalid_choice and saves nothing', function () {
        edInstall(edBuilder('festival', 'free'));
        edInstall(edBuilder('galeria', 'free'));
        [$event, $m] = edEvent(['template_id' => edTemplateId('festival')]);

        foreach (['nope', ['a'], str_repeat('x', 65), 5] as $bad) {
            tpPut($this, $event, $m['owner'], ['template_id' => edTemplateId('galeria'), 'preset' => $bad])
                ->assertStatus(422)->assertJsonPath('errors.preset.0', 'invalid_choice');
        }
        expect($event->fresh()->template_id)->toBe(edTemplateId('festival'));
    });

    it('refuses a palette for a template that has none (the classic)', function () {
        edInstall(edBuilder('festival', 'free'));
        [$event, $m] = edEvent(['template_id' => edTemplateId('festival')]);
        $classicId = InvitationTemplate::where('slug', '!=', 'festival-quinceanera')->where('event_type_id', $event->event_type_id)->value('id');

        tpPut($this, $event, $m['owner'], ['template_id' => $classicId, 'preset' => 'rosa'])->assertStatus(422)->assertJsonPath('errors.preset.0', 'invalid_choice');
    });

    it('keeps the plan and role rules: viewer 403, premium template without plan 403, other event untouched', function () {
        edInstall(edBuilder('festival', 'free'));
        edInstall(edBuilder('galeria', 'premium'));
        [$event, $m] = edEvent(['template_id' => edTemplateId('festival')]);
        [$other, $om] = edEvent(['template_id' => edTemplateId('festival'), 'custom_url_slug' => 'otro']);

        tpPut($this, $event, $m['owner'], ['template_id' => edTemplateId('galeria'), 'preset' => 'oliva'])->assertForbidden()->assertJsonPath('code', 'plan_required');
        tpPut($this, $other, $m['owner'], ['template_id' => edTemplateId('festival'), 'preset' => 'oliva'])->assertNotFound();
        expect($event->fresh()->template_config)->toBeNull()->and($other->fresh()->template_config)->toBeNull();
        if (isset($m['viewer'])) {
            tpPut($this, $event, $m['viewer'], ['template_id' => edTemplateId('festival'), 'preset' => 'oliva'])->assertForbidden();
        }
    });

    it('does not save a palette once the invitation is published', function () {
        edInstall(edBuilder('festival', 'free'));
        [$event, $m] = edEvent(['template_id' => edTemplateId('festival')]);
        $event->forceFill(['template_version_id' => InvitationTemplate::find(edTemplateId('festival'))->current_version_id, 'invitation_stage' => Events::STAGE_INVITATION])->save();

        tpPut($this, $event, $m['owner'], ['template_id' => edTemplateId('festival'), 'preset' => 'oliva'])->assertStatus(409)->assertJsonPath('code', 'template_locked');
    });
});

describe('invitation:template-meta', function () {
    it('stores description and tags, and rejects HTML and long texts', function () {
        $t = InvitationTemplate::first();

        $this->artisan('invitation:template-meta', ['slug' => $t->slug, '--description-es' => 'Elegante y cálida', '--description-en' => 'Warm', '--tag' => ['elegante', 'floral']])->assertSuccessful();
        expect($t->fresh()->description)->toBe(['es' => 'Elegante y cálida', 'en' => 'Warm'])->and($t->fresh()->tags)->toBe(['elegante', 'floral']);

        $this->artisan('invitation:template-meta', ['slug' => $t->slug, '--description-es' => '<b>x</b>'])->assertFailed();
        $this->artisan('invitation:template-meta', ['slug' => $t->slug, '--description-en' => str_repeat('a', 201)])->assertFailed();
        $this->artisan('invitation:template-meta', ['slug' => $t->slug, '--tag' => ['Bad Tag']])->assertFailed();
        $this->artisan('invitation:template-meta', ['slug' => 'nope'])->assertFailed();
        expect($t->fresh()->description['es'])->toBe('Elegante y cálida');

        $this->artisan('invitation:template-meta', ['slug' => $t->slug, '--clear-tags' => true])->assertSuccessful();
        expect($t->fresh()->tags)->toBe([]);
    });
});
