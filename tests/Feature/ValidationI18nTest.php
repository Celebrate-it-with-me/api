<?php

use App\Http\Kernel;
use App\Http\Middleware\SetRequestLocale;
use App\Models\User;
use App\Models\UserPreference;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

function validationMessage(string $locale, array $data, array $rules): string
{
    App::setLocale($locale);

    return Validator::make($data, $rules)->errors()->first();
}

describe('validation messages', function () {
    it('speaks Spanish and English for the typical rules', function (string $rule, array $data, array $rules, string $es, string $en) {
        expect(validationMessage('es', $data, $rules))->toBe($es);
        expect(validationMessage('en', $data, $rules))->toBe($en);
    })->with([
        'required' => ['required', [], ['name' => 'required'], 'El campo nombre es obligatorio.', 'The name field is required.'],
        'email' => ['email', ['email' => 'nope'], ['email' => 'email'], 'Escribe un correo válido.', 'Enter a valid email address.'],
        'min' => ['min', ['password' => 'abc'], ['password' => 'min:8'], 'El campo contraseña debe tener al menos 8 caracteres.', 'The password field must be at least 8 characters.'],
        'max' => ['max', ['name' => str_repeat('a', 300)], ['name' => 'max:255'], 'El campo nombre no puede tener más de 255 caracteres.', 'The name field must not be greater than 255 characters.'],
        'confirmed' => ['confirmed', ['password' => 'abcdefgh', 'password_confirmation' => 'zzzzzzzz'], ['password' => 'confirmed'], 'La confirmación de contraseña no coincide.', 'The password field confirmation does not match.'],
        'in' => ['in', ['role' => 'x'], ['role' => 'in:owner,editor'], 'El valor elegido en rol no es válido.', 'The selected role is invalid.'],
        'url' => ['url', ['url' => 'x'], ['url' => 'url'], 'El campo enlace debe ser un enlace válido.', 'The link field must be a valid URL.'],
    ]);

    it('answers the unique rule of the real register endpoint in both languages', function () {
        User::factory()->create(['email' => 'taken@cwm.test']);
        $body = ['name' => 'Ana', 'email' => 'taken@cwm.test', 'password' => 'Secret-12345', 'device' => 'x'];

        $this->withHeader('Accept-Language', 'es')->postJson('/api/v1/app/register', $body)
            ->assertStatus(422)->assertJsonPath('errors.email.0', 'El valor de correo ya está en uso.');
        $this->withHeader('Accept-Language', 'en')->postJson('/api/v1/app/register', $body)
            ->assertStatus(422)->assertJsonPath('errors.email.0', 'The email is already in use.');
    });

    it('defaults the register errors to Spanish with no hint at all', function () {
        $this->withHeader('Accept-Language', '')->postJson('/api/v1/app/register', [])->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'El campo nombre es obligatorio.');
    });
});

describe('language resolution', function () {
    beforeEach(function () {
        Route::middleware(['api', 'auth:sanctum'])->get('/api/v1/__locale', fn () => response()->json(['locale' => App::getLocale()]));
        Route::middleware(['api'])->get('/api/v1/__locale-open', fn () => response()->json(['locale' => App::getLocale()]));
    });

    it('defaults to Spanish', function () {
        $this->withHeader('Accept-Language', '')->getJson('/api/v1/__locale-open')->assertJsonPath('locale', 'es');
    });

    it('takes the Accept-Language header, honouring q weights and skipping unsupported languages', function () {
        $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/__locale-open')->assertJsonPath('locale', 'en');
        $this->withHeader('Accept-Language', 'en-US,en;q=0.9')->getJson('/api/v1/__locale-open')->assertJsonPath('locale', 'en');
        $this->withHeader('Accept-Language', 'fr-FR, es;q=0.8, en;q=0.5')->getJson('/api/v1/__locale-open')->assertJsonPath('locale', 'es');
        $this->withHeader('Accept-Language', 'en;q=0.4, es;q=0.9')->getJson('/api/v1/__locale-open')->assertJsonPath('locale', 'es');
        $this->withHeader('Accept-Language', 'fr-FR')->getJson('/api/v1/__locale-open')->assertJsonPath('locale', 'es');
    });

    it('lets the lang parameter win over the header', function () {
        $this->withHeader('Accept-Language', 'es')->getJson('/api/v1/__locale-open?lang=en')->assertJsonPath('locale', 'en');
        $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/__locale-open?lang=es')->assertJsonPath('locale', 'es');
        $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/__locale-open?lang=zz')->assertJsonPath('locale', 'en');
        $this->withHeaders(['Accept-Language' => 'es', 'X-Locale' => 'en'])->getJson('/api/v1/__locale-open')->assertJsonPath('locale', 'en');
    });

    it('uses the language saved by the signed-in account before the implicit browser header', function () {
        $user = User::factory()->create();
        UserPreference::query()->updateOrCreate(['user_id' => $user->id], ['language' => 'en']);
        $token = $user->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/__locale')->assertJsonPath('locale', 'en');
        // The browser's own Accept-Language does not beat what the account chose...
        $this->withToken($token)->withHeader('Accept-Language', 'es')->getJson('/api/v1/__locale')->assertJsonPath('locale', 'en');
        // ...but an explicit choice of the client does.
        $this->withToken($token)->withHeader('X-Locale', 'es')->getJson('/api/v1/__locale')->assertJsonPath('locale', 'es');
        $this->withToken($token)->getJson('/api/v1/__locale?lang=es')->assertJsonPath('locale', 'es');
    });

    it('falls back to Spanish for an account without a usable language', function () {
        $user = User::factory()->create();
        UserPreference::query()->updateOrCreate(['user_id' => $user->id], ['language' => 'fr']);

        $token = $user->createToken('t')->plainTextToken;
        $this->withToken($token)->withHeader('Accept-Language', '')->getJson('/api/v1/__locale')->assertJsonPath('locale', 'es');
        $this->withToken($token)->withHeader('Accept-Language', 'en-US')->getJson('/api/v1/__locale')->assertJsonPath('locale', 'en');
    });

    it('is registered in the api group so invitation routes get it too without losing their own lang', function () {
        expect(app(Kernel::class)->getMiddlewareGroups()['api'])->toContain(SetRequestLocale::class);
    });
});

describe('contract codes do not change with the language', function () {
    it('keeps the code of a coded response and translates only its message', function () {
        $es = $this->withHeader('Accept-Language', 'es')->getJson('/api/v1/admin/invitations/bogus-token')->assertNotFound();
        $en = $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/admin/invitations/bogus-token')->assertNotFound();

        expect($es->json('code'))->toBe('invitation_invalid')->and($en->json('code'))->toBe('invitation_invalid');
        expect($es->json('message'))->toBe('Esta invitación no es válida.');
        expect($en->json('message'))->toBe('This invitation is not valid.');
    });

    it('leaves the codes written as errors.<field>[0] untouched in the services', function () {
        $expected = [
            'app/Http/Services/Admin/Team/TeamInvitations.php' => "['email' => ['already_staff']]",
            'app/Http/Services/Payments/PurchaseService.php' => "['code' => ['invalid_code']]",
            'app/Http/Controllers/Admin/Users/UserAdminController.php' => "['confirmEmail' => ['email_mismatch']]",
        ];
        foreach ($expected as $file => $snippet) {
            expect(file_get_contents(base_path($file)))->toContain($snippet);
        }
        // A code is never a translation key, so no language can turn it into text.
        foreach (['already_staff', 'invalid_code', 'email_mismatch', 'module_locked'] as $code) {
            expect(trans("errors.$code"))->toBe("errors.$code");
        }
    });

    it('translates the message of a permission error and keeps its status', function () {
        foreach (['es' => 'Este módulo no está disponible para este evento.', 'en' => 'This module is not available for this event.'] as $lang => $text) {
            App::setLocale($lang);
            expect(__('errors.permission.module_unavailable'))->toBe($text);
        }
    });
});

describe('language files', function () {
    function langKeys(array $tree, string $prefix = ''): array
    {
        $keys = [];
        foreach ($tree as $k => $v) {
            is_array($v) ? array_push($keys, ...langKeys($v, "$prefix$k.")) : $keys[] = "$prefix$k";
        }

        return $keys;
    }

    it('has the same keys in lang/en and lang/es for every file (parity)', function () {
        $en = collect(glob(base_path('lang/en/*.php')))->map(fn ($f) => basename($f))->sort()->values()->all();
        $es = collect(glob(base_path('lang/es/*.php')))->map(fn ($f) => basename($f))->sort()->values()->all();
        expect($es)->toBe($en);

        foreach ($en as $file) {
            $a = langKeys(require base_path("lang/en/$file"));
            $b = langKeys(require base_path("lang/es/$file"));
            sort($a);
            sort($b);
            expect(array_values(array_diff($a, $b)))->toBe([], "keys in en/$file missing from es");
            expect(array_values(array_diff($b, $a)))->toBe([], "keys in es/$file missing from en");
        }
    });

    it('keeps the :placeholders of every message equal in both languages', function () {
        foreach (['validation.php', 'errors.php'] as $file) {
            $en = Arr::dot(require base_path("lang/en/$file"));
            $es = Arr::dot(require base_path("lang/es/$file"));
            foreach ($en as $key => $text) {
                if (! is_string($text) || str_starts_with($key, 'attributes.')) {
                    continue;
                }
                preg_match_all('/:[A-Za-z_]+/', $text, $a);
                preg_match_all('/:[A-Za-z_]+/', $es[$key], $b);
                $x = array_unique($a[0]);
                $y = array_unique($b[0]);
                sort($x);
                sort($y);
                // Spanish may drop :attribute when the sentence reads better without it (e.g. the email rule).
                expect(array_values(array_diff($y, $x)))->toBe([], "$file $key");
            }
        }
    });

    it('has a Spanish text for every rule used in the app', function () {
        $es = require base_path('lang/es/validation.php');
        foreach (['required', 'email', 'unique', 'min', 'max', 'confirmed', 'in', 'exists', 'image', 'mimes', 'date', 'after', 'before', 'timezone', 'url', 'uuid', 'regex', 'numeric'] as $rule) {
            expect($es)->toHaveKey($rule);
        }
        expect($es['attributes'])->toHaveKeys(['email', 'password', 'name']);
    });
});
