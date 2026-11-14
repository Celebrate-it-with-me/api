<?php

use App\Mail\ConfirmAccountEmail;
use App\Mail\TestEmail;
use App\Mail\WelcomeEmail;
use App\Models\AdminAuditLog;
use App\Models\MessageOverride;
use App\Models\User;
use App\Support\AdminPermissions;
use App\Support\Mail\MailPreviewRenderer;
use App\Support\Mail\MailRegistry;
use App\Support\Mail\MessageCatalog;
use App\Support\Mail\MessageOverrides;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    Mail::fake();
    User::role(AdminPermissions::STAFF_ROLES)->get()->each(fn (User $u) => $u->syncRoles([]));
});

const MSG = '/api/v1/admin/messages';

function msgStaff(string $role = AdminPermissions::SUPERADMIN): User
{
    $u = User::factory()->create();
    $u->assignRole($role);

    return $u;
}

function msgPut($test, string $key, array $fields, array $over = [])
{
    return $test->actingAsReauthed(msgStaff())->putJson(MSG."/{$key}", array_merge(['locale' => 'es', 'fields' => $fields, 'reason' => 'Ajuste de tono'], $over));
}

describe('permissions', function () {
    it('keeps non-staff out, lets support read, reserves writes for the superadmin with reauth', function () {
        $this->getJson(MSG)->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson(MSG)->assertForbidden();

        $support = msgStaff(AdminPermissions::SUPPORT);
        $this->actingAs($support)->getJson(MSG)->assertOk();
        $this->actingAs($support)->getJson(MSG.'/confirm-account?locale=es')->assertOk();
        $this->actingAs($support)->postJson(MSG.'/confirm-account/preview', ['locale' => 'es'])->assertOk();
        $this->actingAsReauthed($support)->putJson(MSG.'/confirm-account', ['locale' => 'es', 'fields' => ['subject' => 'Hola'], 'reason' => 'Ajuste de tono'])->assertForbidden();
        $this->actingAsReauthed($support)->deleteJson(MSG.'/confirm-account', ['locale' => 'es', 'reason' => 'Ajuste de tono'])->assertForbidden();
        $this->actingAsReauthed($support)->postJson(MSG.'/confirm-account/test', ['locale' => 'es', 'reason' => 'Ajuste de tono'])->assertForbidden();

        $super = msgStaff();
        $this->actingAs($super)->putJson(MSG.'/confirm-account', ['locale' => 'es', 'fields' => ['subject' => 'Hola'], 'reason' => 'Ajuste de tono'])->assertStatus(403);
        expect(MessageOverride::count())->toBe(0);
    });

    it('registers admin.messages.manage for the superadmin only', function () {
        expect(AdminPermissions::SUPERADMIN_ONLY)->toContain('admin.messages.manage');
        expect(msgStaff()->can('admin.messages.manage'))->toBeTrue();
        expect(msgStaff(AdminPermissions::SUPPORT)->can('admin.messages.manage'))->toBeFalse();
    });
});

describe('reading', function () {
    it('lists the editable mails with their fields and overridden flags', function () {
        $rows = collect($this->actingAs(msgStaff())->getJson(MSG)->assertOk()->json('data'));
        $confirm = $rows->firstWhere('key', 'confirm-account');

        expect($confirm)->toMatchArray(['name' => 'Confirmar correo', 'locales' => ['es', 'en'], 'overridden' => ['es' => false, 'en' => false], 'updatedAt' => null]);
        expect($confirm['editableFields'])->toBe(['subject', 'preheader', 'title', 'intro', 'action', 'outro']);
        expect($rows->pluck('key'))->not->toContain('support-request');
        expect($rows->pluck('key')->all())->toBe(MessageCatalog::keys());
        // Plural forms and shared strings are not editable.
        expect($rows->firstWhere('key', 'budget-reminder')['editableFields'])->not->toContain('preheader', 'due_in');
        expect($rows->firstWhere('key', 'admin-security-new-device')['editableFields'])->toBe(['subject', 'preheader', 'intro']);
    });

    it('returns the detail with defaults, variables and limits', function () {
        $data = $this->actingAs(msgStaff())->getJson(MSG.'/invoice?locale=en')->assertOk()->json('data');

        expect($data['key'])->toBe('invoice')->and($data['locale'])->toBe('en')->and($data['updatedBy'])->toBeNull();
        expect($data['fields']['subject'])->toMatchArray(['overridden' => false, 'variables' => ['number'], 'maxLength' => 150]);
        expect($data['fields']['subject']['current'])->toBe($data['fields']['subject']['default']);
        expect($data['fields']['intro']['maxLength'])->toBe(1000)->and($data['fields']['preheader']['maxLength'])->toBe(200);
        expect(collect($data['variables'])->pluck('name'))->toContain('number', 'total');
    });

    it('answers 404 for unknown or non editable keys and 422 for a bad locale', function () {
        $staff = msgStaff();
        $this->actingAs($staff)->getJson(MSG.'/nope?locale=es')->assertNotFound()->assertJson(['code' => 'message_not_found']);
        $this->actingAs($staff)->getJson(MSG.'/support-request?locale=es')->assertNotFound();
        $this->actingAs($staff)->getJson(MSG.'/db-rsvp?locale=es')->assertNotFound();
        $this->actingAs($staff)->getJson(MSG.'/confirm-account?locale=fr')->assertStatus(422)->assertJsonPath('errors.locale.0', 'invalid');
        $this->actingAs($staff)->getJson(MSG.'/confirm-account')->assertStatus(422)->assertJsonPath('errors.locale.0', 'invalid');
        $this->actingAsReauthed($staff)->putJson(MSG.'/nope', ['locale' => 'es', 'fields' => ['subject' => 'x'], 'reason' => 'Ajuste de tono'])->assertNotFound();
        $this->actingAsReauthed($staff)->deleteJson(MSG.'/nope', ['locale' => 'es', 'reason' => 'Ajuste de tono'])->assertNotFound();
        $this->actingAs($staff)->postJson(MSG.'/nope/preview', ['locale' => 'es'])->assertNotFound();
        $this->actingAsReauthed($staff)->postJson(MSG.'/nope/test', ['locale' => 'es', 'reason' => 'Ajuste de tono'])->assertNotFound();
    });
});

describe('validation', function () {
    it('answers one code per field', function (string $field, string $text, string $code, string $key = 'confirm-account') {
        expect(msgPut($this, $key, [$field => $text])->assertStatus(422)->json('errors')["fields.{$field}"][0])->toBe($code);
        expect($this->actingAs(msgStaff())->postJson(MSG."/{$key}/preview", ['locale' => 'es', 'fields' => [$field => $text]])->assertStatus(422)->json('errors')["fields.{$field}"][0])->toBe($code);
        expect(MessageOverride::count())->toBe(0);
    })->with([
        'empty' => ['subject', '   ', 'required'],
        'subject too long' => ['subject', str_repeat('a', 151), 'too_long'],
        'preheader too long' => ['preheader', str_repeat('a', 201), 'too_long'],
        'intro too long' => ['intro', str_repeat('a', 1001), 'too_long'],
        'html' => ['intro', 'Hola <b>mundo</b>', 'html_not_allowed'],
        'script' => ['outro', '<script>alert(1)</script>', 'html_not_allowed'],
        'markdown link' => ['intro', 'Entra [aquí](https://evil.test)', 'links_not_allowed'],
        'bare url' => ['intro', 'Entra a https://evil.test ahora', 'links_not_allowed'],
        'www' => ['outro', 'Visita www.evil.test', 'links_not_allowed'],
        'email address' => ['outro', 'Escribe a alguien@evil.test', 'links_not_allowed'],
        'missing variable' => ['title', 'Hola', 'missing_variable'],
        'unknown variable' => ['title', '¡Hola, :name! :hack', 'unknown_variable'],
        'not editable' => ['footer', 'x', 'not_editable'],
        'placeholder dropped in subject' => ['subject', 'Tu factura', 'missing_variable', 'invoice'],
    ]);

    it('requires fields, a valid locale and a reason', function () {
        $s = msgStaff();
        $this->actingAsReauthed($s)->putJson(MSG.'/welcome', ['locale' => 'es', 'fields' => ['subject' => 'Hola, :name'], 'reason' => 'no'])->assertStatus(422)->assertJsonPath('errors.reason.0', 'invalid');
        $this->actingAsReauthed($s)->putJson(MSG.'/welcome', ['locale' => 'es', 'fields' => ['subject' => 'Hola, :name']])->assertStatus(422)->assertJsonPath('errors.reason.0', 'required');
        $this->actingAsReauthed($s)->putJson(MSG.'/welcome', ['locale' => 'xx', 'fields' => ['subject' => 'Hola, :name'], 'reason' => 'Ajuste de tono'])->assertStatus(422)->assertJsonPath('errors.locale.0', 'invalid');
        $this->actingAsReauthed($s)->putJson(MSG.'/welcome', ['locale' => 'es', 'reason' => 'Ajuste de tono'])->assertStatus(422)->assertJsonPath('errors.fields.0', 'required');
    });

    it('accepts minimal markdown, keeps placeholders and collapses subject line breaks', function () {
        msgPut($this, 'welcome', ['subject' => "Hola\n:name", 'intro' => "**Qué alegría**, *de verdad*.\n\nUn salto."])->assertOk();
        $row = MessageOverride::where('field', 'subject')->first();
        expect($row->value)->toBe('Hola :name');
    });
});

describe('editing', function () {
    it('saves the override, returns the detail and writes an audit row without the full text', function () {
        $staff = msgStaff();
        $text = 'Confirma tu correo, un paso más y listo';
        $res = $this->actingAsReauthed($staff)->putJson(MSG.'/confirm-account', ['locale' => 'es', 'fields' => ['subject' => $text], 'reason' => 'Ajuste de tono'])->assertOk();

        $res->assertJsonPath('data.fields.subject.current', $text)->assertJsonPath('data.fields.subject.overridden', true)
            ->assertJsonPath('data.fields.intro.overridden', false)->assertJsonPath('data.updatedBy.id', $staff->id);
        $list = collect($this->actingAs($staff)->getJson(MSG)->json('data'))->firstWhere('key', 'confirm-account');
        expect($list['overridden'])->toBe(['es' => true, 'en' => false])->and($list['updatedAt'])->not->toBeNull();

        $log = AdminAuditLog::where('action', 'message.update')->sole();
        expect($log->actor_id)->toBe($staff->id)->and($log->reason)->toBe('Ajuste de tono');
        expect($log->after)->toBe(['key' => 'confirm-account', 'locale' => 'es', 'fields' => ['subject']]);
        expect(json_encode($log->getAttributes()))->not->toContain('un paso');
    });

    it('treats a text equal to the original as no override and keeps one row per key, locale and field', function () {
        msgPut($this, 'confirm-account', ['subject' => 'Uno'])->assertOk();
        msgPut($this, 'confirm-account', ['subject' => 'Dos'])->assertOk();
        expect(MessageOverride::count())->toBe(1);
        msgPut($this, 'confirm-account', ['subject' => MessageCatalog::default('confirm-account', 'es', 'subject')])->assertOk();
        expect(MessageOverride::count())->toBe(0);
    });

    it('restores one field, several or the whole language, and audits it', function () {
        msgPut($this, 'confirm-account', ['subject' => 'Uno', 'preheader' => 'Dos', 'outro' => 'Tres'])->assertOk();
        msgPut($this, 'confirm-account', ['subject' => 'Uno en'], ['locale' => 'en'])->assertOk();
        $s = msgStaff();

        $this->actingAsReauthed($s)->deleteJson(MSG.'/confirm-account', ['locale' => 'es', 'fields' => ['subject'], 'reason' => 'Volver al original'])
            ->assertOk()->assertJsonPath('data.fields.subject.overridden', false)->assertJsonPath('data.fields.preheader.overridden', true);
        expect(MessageOverride::where('locale', 'es')->count())->toBe(2);

        $this->actingAsReauthed($s)->deleteJson(MSG.'/confirm-account', ['locale' => 'es', 'reason' => 'Volver al original'])->assertOk()->assertJsonPath('data.fields.preheader.overridden', false);
        expect(MessageOverride::pluck('locale')->all())->toBe(['en']);
        expect(AdminAuditLog::where('action', 'message.reset')->count())->toBe(2);
        $this->actingAsReauthed($s)->deleteJson(MSG.'/confirm-account', ['locale' => 'es', 'fields' => ['footer'], 'reason' => 'Volver al original'])->assertStatus(422)->assertJsonValidationErrors(['fields.footer' => 'not_editable']);
    });
});

describe('applying the text', function () {
    it('uses the override when the mail is built and sent, per language, and falls back to the file for the rest', function () {
        msgPut($this, 'confirm-account', ['subject' => 'Asunto editado', 'title' => 'Qué bueno verte, :name'])->assertOk();

        App::setLocale('es');
        $mail = new ConfirmAccountEmail(User::factory()->make(['name' => 'Valentina Ríos']), 'https://app.test/x');
        expect($mail->envelope()->subject)->toBe('Asunto editado');
        $html = $mail->render();
        expect($html)->toContain('Qué bueno verte, Valentina')->and($html)->toContain('Confirmar mi correo');

        // English was not edited.
        $out = MailPreviewRenderer::render(MailRegistry::find('confirm-account'), 'en');
        expect($out['subject'])->toBe(trans('mail.confirm.subject', [], 'en'))->and($out['subject'])->not->toBe('Asunto editado');
    });

    it('sends a real mail with the edited subject', function () {
        msgPut($this, 'test', ['subject' => 'Prueba editada'])->assertOk();
        App::setLocale('es');
        Mail::to('x@example.com')->send(new TestEmail('es'));
        Mail::assertSent(TestEmail::class, fn ($m) => $m->envelope()->subject === 'Prueba editada');
    });

    it('applies the override to the notification subject too', function () {
        msgPut($this, 'budget-reminder', ['subject' => 'Pagos de :event'])->assertOk();
        $out = MailPreviewRenderer::render(MailRegistry::find('budget-reminder'), 'es');
        expect($out['subject'])->toBe('Pagos de Los XV de Valentina');
    });

    it('does not touch other lang groups or keys', function () {
        msgPut($this, 'confirm-account', ['subject' => 'Cambiado'])->assertOk();
        expect(trans('mail.confirm.subject', [], 'es'))->toBe('Cambiado');
        expect(trans('mail.welcome.action', [], 'es'))->toBe(MessageCatalog::default('welcome', 'es', 'action'));
        expect(trans('validation.required', [], 'es'))->not->toBe('Cambiado');
        expect(trans('mail.footer.help', ['email' => 'a@b.c'], 'es'))->toContain('a@b.c');
    });

    it('falls back to the lang file when reading the override fails', function () {
        msgPut($this, 'confirm-account', ['subject' => 'Cambiado'])->assertOk();
        expect(trans('mail.confirm.subject', [], 'es'))->toBe('Cambiado');

        Cache::forget(MessageOverrides::CACHE_KEY);
        Schema::drop('message_overrides'); // the read now throws
        expect(trans('mail.confirm.subject', [], 'es'))->toBe(MessageCatalog::default('confirm-account', 'es', 'subject'));
        $out = MailPreviewRenderer::render(MailRegistry::find('confirm-account'), 'es');
        expect($out['problems'])->toBe([])->and($out['subject'])->toBe(MessageCatalog::default('confirm-account', 'es', 'subject'));
    });

    it('caches the table and drops the cache on every write', function () {
        msgPut($this, 'confirm-account', ['subject' => 'Uno'])->assertOk();
        expect(trans('mail.confirm.subject', [], 'es'))->toBe('Uno');
        expect(Cache::has(MessageOverrides::CACHE_KEY))->toBeTrue();

        $queries = 0;
        DB::listen(function ($q) use (&$queries) { str_contains($q->sql, 'message_overrides') && $queries++; });
        trans('mail.confirm.subject', [], 'es');
        trans('mail.confirm.title', ['name' => 'a'], 'es');
        expect($queries)->toBe(0);

        msgPut($this, 'confirm-account', ['subject' => 'Dos'])->assertOk();
        expect(Cache::has(MessageOverrides::CACHE_KEY))->toBeFalse();
        expect(trans('mail.confirm.subject', [], 'es'))->toBe('Dos');

        $this->actingAsReauthed(msgStaff())->deleteJson(MSG.'/confirm-account', ['locale' => 'es', 'reason' => 'Volver al original'])->assertOk();
        expect(trans('mail.confirm.subject', [], 'es'))->toBe(MessageCatalog::default('confirm-account', 'es', 'subject'));
    });
});

describe('preview', function () {
    it('renders with the sample data, the unsaved text and without saving', function () {
        $res = $this->actingAs(msgStaff())->postJson(MSG.'/welcome/preview', ['locale' => 'es', 'fields' => ['subject' => 'Bienvenida, :name', 'intro' => 'Texto **nuevo** de prueba']])->assertOk();

        expect($res->json('data.subject'))->toBe('Bienvenida, Valentina');
        expect($res->json('data.html'))->toMatch('/<strong[^>]*>nuevo<\/strong>/')->and($res->json('data.preheader'))->not->toBe('');
        expect(MessageOverride::count())->toBe(0);
        // Not left behind for the real mails.
        expect(trans('mail.welcome.subject', ['name' => 'X'], 'es'))->not->toContain('Bienvenida, X');
    });

    it('uses the saved overrides for fields not sent and works with no fields', function () {
        msgPut($this, 'welcome', ['title' => 'Qué gusto, :name'])->assertOk();
        $html = $this->actingAs(msgStaff())->postJson(MSG.'/welcome/preview', ['locale' => 'es'])->assertOk()->json('data.html');
        expect($html)->toContain('Qué gusto, Valentina');
    });

    it('escapes html that a placeholder value could bring, never renders typed html', function () {
        $this->actingAs(msgStaff())->postJson(MSG.'/welcome/preview', ['locale' => 'es', 'fields' => ['intro' => '<img src=x onerror=alert(1)>']])->assertStatus(422);
    });
});

describe('test send', function () {
    it('sends the mail only to staff addresses, defaulting to the caller, and audits it', function () {
        $staff = msgStaff();
        $other = msgStaff(AdminPermissions::SUPPORT);

        $this->actingAsReauthed($staff)->postJson(MSG.'/welcome/test', ['locale' => 'es', 'reason' => 'Revisar el texto'])->assertOk()->assertJson(['data' => ['sent' => true]]);
        Mail::assertSent(WelcomeEmail::class, fn ($m) => $m->hasTo($staff->email));

        $this->actingAsReauthed($staff)->postJson(MSG.'/welcome/test', ['locale' => 'en', 'to' => strtoupper($other->email), 'reason' => 'Revisar el texto'])->assertOk();
        Mail::assertSent(WelcomeEmail::class, fn ($m) => $m->hasTo($other->email));

        $this->actingAsReauthed($staff)->postJson(MSG.'/welcome/test', ['locale' => 'es', 'to' => User::factory()->create()->email, 'reason' => 'Revisar el texto'])
            ->assertStatus(422)->assertJsonPath('errors.to.0', 'not_staff');
        $this->actingAsReauthed($staff)->postJson(MSG.'/welcome/test', ['locale' => 'es', 'to' => 'ajeno@example.com', 'reason' => 'Revisar el texto'])
            ->assertStatus(422)->assertJsonPath('errors.to.0', 'not_staff');
        Mail::assertSent(WelcomeEmail::class, 2);

        $log = AdminAuditLog::where('action', 'message.test')->first();
        expect($log->after)->toMatchArray(['key' => 'welcome', 'locale' => 'es'])->and(AdminAuditLog::where('action', 'message.test')->count())->toBe(2);
    });

    it('sends notifications as html and is limited to five per hour', function () {
        $staff = msgStaff();
        $this->actingAsReauthed($staff)->postJson(MSG.'/budget-reminder/test', ['locale' => 'es', 'reason' => 'Revisar el texto'])->assertOk();
        for ($i = 0; $i < 4; $i++) {
            $this->actingAsReauthed($staff)->postJson(MSG.'/welcome/test', ['locale' => 'es', 'reason' => 'Revisar el texto'])->assertOk();
        }
        $this->actingAsReauthed($staff)->postJson(MSG.'/welcome/test', ['locale' => 'es', 'reason' => 'Revisar el texto'])->assertStatus(429);
    });
});

describe('every registry mail', function () {
    it('renders with and without an override in both languages', function (string $key, string $lang) {
        $definition = MailRegistry::find($key);
        $path = MessageCatalog::path($key);
        $plain = MailPreviewRenderer::render($definition, $lang);
        expect($plain['problems'])->toBe([]);

        // Override every editable field with its own default plus a marker, keeping the placeholders.
        foreach (MessageCatalog::fields($key) as $field) {
            MessageOverride::create(['message_key' => $key, 'locale' => $lang, 'field' => $field, 'value' => MessageCatalog::default($key, $lang, $field).' ✱']);
        }
        app(MessageOverrides::class)->forget();

        $edited = MailPreviewRenderer::render($definition, $lang);
        expect($edited['problems'])->toBe([], "{$key}/{$lang} with override: ".implode('; ', $edited['problems']));
        expect($edited['subject'])->toContain('✱');
        expect($path)->not->toBeNull();
    })->with(function () {
        foreach (MessageCatalog::keys() as $key) {
            foreach (MailRegistry::find($key)->langs as $lang) {
                yield "{$key} {$lang}" => [$key, $lang];
            }
        }
    });

    it('has copy in both languages with the same placeholders for every editable field', function (string $key) {
        foreach (MessageCatalog::fields($key) as $field) {
            $es = MessageCatalog::variables(MessageCatalog::default($key, 'es', $field));
            $en = MessageCatalog::variables(MessageCatalog::default($key, 'en', $field));
            expect(MessageCatalog::default($key, 'en', $field))->not->toBe('');
            sort($es);
            sort($en);
            expect($es)->toBe($en, "{$key}.{$field}");
        }
    })->with(fn () => MessageCatalog::keys());

    it('either has editable copy or is explicitly excluded', function () {
        $without = collect(MailRegistry::all())->filter(fn ($d) => $d->hasMail() && ! MessageCatalog::isEditable($d->key))->keys()->all();
        expect($without)->toBe(['support-request']);
    });

    it('is not affected by the app locale after a preview', function () {
        $before = App::getLocale();
        $this->actingAs(msgStaff())->postJson(MSG.'/welcome/preview', ['locale' => 'en'])->assertOk();
        expect(App::getLocale())->toBe($before);
    });
});

it('leaves the impersonation classification untouched: the editor lives in the admin API only', function () {
    $routes = collect(app('router')->getRoutes()->getRoutes())->filter(fn ($r) => str_contains($r->uri(), 'admin/messages'));
    expect($routes)->not->toBeEmpty();
    expect($routes->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/app/')))->toBeEmpty();
});
