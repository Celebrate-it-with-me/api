<?php

use App\Support\Mail\MailPreviewRenderer;
use App\Support\Mail\MailRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

/** Every Mailable and Notification class declared under app/. */
function declaredMailClasses(): array
{
    $classes = [];
    foreach (File::allFiles(app_path()) as $file) {
        $class = 'App\\' . str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
        if (! class_exists($class)) {
            continue;
        }
        $reflection = new ReflectionClass($class);
        if (! $reflection->isAbstract() && ($reflection->isSubclassOf(Mailable::class) || $reflection->isSubclassOf(Notification::class))) {
            $classes[] = $class;
        }
    }

    return $classes;
}

it('lists every Mailable and Notification of app/ in the registry', function () {
    $missing = array_diff(declaredMailClasses(), MailRegistry::classes());

    expect($missing)->toBeEmpty('Register these in App\Support\Mail\MailRegistry: ' . implode(', ', $missing));
    expect(declaredMailClasses())->not->toBeEmpty();
});

it('has no registry entry for a class that does not exist', function () {
    foreach (MailRegistry::all() as $definition) {
        expect(class_exists($definition->class))->toBeTrue("{$definition->key} points at a missing class");
    }
});

it('renders every mail in each of its languages without leftovers', function (string $key, string $lang) {
    $definition = MailRegistry::find($key);
    $out = MailPreviewRenderer::render($definition, $lang);

    expect($out['problems'])->toBe([], "{$key}/{$lang}: " . implode('; ', $out['problems']));
    expect($out['subject'])->not->toBe('');
    if ($key !== 'support-request') { // internal plain text, no brand layout
        expect($out['html'])->toContain('Quinvite');
    }
})->with(function () {
    foreach (MailRegistry::all() as $definition) {
        foreach ($definition->hasMail() ? $definition->langs : [] as $lang) {
            yield "{$definition->key} {$lang}" => [$definition->key, $lang];
        }
    }
});

it('writes a Spanish and an English version of every branded mail', function () {
    foreach (MailRegistry::all() as $definition) {
        if ($definition->hasMail() && $definition->key !== 'support-request') {
            expect($definition->langs)->toBe(['es', 'en'], $definition->key);
        }
    }
});

it('speaks the language of the user and keeps the sample data in the text', function () {
    $es = MailPreviewRenderer::render(MailRegistry::find('welcome'), 'es');
    $en = MailPreviewRenderer::render(MailRegistry::find('welcome'), 'en');

    expect($es['subject'])->toContain('bienvenida')->and($en['subject'])->toContain('Welcome');
    expect($es['html'])->toContain('lang="es"')->and($en['html'])->toContain('lang="en"');
});

it('only uses absolute links, even without APP_FRONT_URL', function () {
    config(['app.frontend_app.url' => null, 'app.url' => 'http://dev.api.cwm.com']);

    foreach (MailRegistry::all() as $definition) {
        foreach ($definition->hasMail() ? $definition->langs : [] as $lang) {
            $html = MailPreviewRenderer::render($definition, $lang)['html'];
            preg_match_all('/href="([^"]*)"/', $html, $links);
            foreach ($links[1] as $href) {
                expect($href)->toMatch('#^(https?://|mailto:)#');
            }
        }
    }
});

it('catches a broken mail: unresolved keys, placeholders and relative links', function () {
    $problems = MailPreviewRenderer::problems('mail.confirm.subject', '', '<p>Hola, :name</p><a href="/dashboard"></a><strong></strong>');

    expect(implode(' | ', $problems))
        ->toContain('unresolved translation keys')
        ->toContain('unreplaced placeholders')
        ->toContain('not absolute')
        ->toContain('missing preheader');
});

it('has the same translation keys in es and en', function () {
    $flatten = fn (array $a) => array_keys(Arr::dot($a));

    expect($flatten(require lang_path('en/mail.php')))->toEqualCanonicalizing($flatten(require lang_path('es/mail.php')));
});

it('mail:preview-all writes an HTML file per mail and language', function () {
    $dir = sys_get_temp_dir() . '/mail-previews-' . uniqid();

    expect(Artisan::call('mail:preview-all', ['--path' => $dir]))->toBe(0);
    expect(File::exists("{$dir}/es/confirm-account.html"))->toBeTrue()
        ->and(File::exists("{$dir}/en/confirm-account.html"))->toBeTrue()
        ->and(File::exists("{$dir}/en/support-request.html"))->toBeFalse();

    File::deleteDirectory($dir);
});

describe('the /dev/mail gallery', function () {
    it('answers in local', function () {
        app()->detectEnvironment(fn () => 'local');

        $this->get('/dev/mail')->assertOk()->assertSee('Recordatorio de pagos');
        $this->get('/dev/mail/confirm-account?lang=en')->assertOk()->assertSee('Confirm your email');
        $this->get('/dev/mail/confirm-account/frame?lang=es')->assertOk()->assertSee('Confirmar mi correo');
    });

    it('is a 404 in production and in testing', function () {
        app()->detectEnvironment(fn () => 'production');
        $this->get('/dev/mail')->assertNotFound();
        $this->get('/dev/mail/confirm-account')->assertNotFound();
        $this->get('/dev/mail/confirm-account/frame')->assertNotFound();

        app()->detectEnvironment(fn () => 'testing');
        $this->get('/dev/mail')->assertNotFound();
    });

    it('404s for an unknown mail or one without mail', function () {
        app()->detectEnvironment(fn () => 'local');

        $this->get('/dev/mail/nope')->assertNotFound();
        $this->get('/dev/mail/db-comment-created')->assertNotFound();
    });
});
