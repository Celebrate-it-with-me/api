<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

const RM_HEADERS = ['Origin' => 'http://dev.app.cwm.com', 'Referer' => 'http://dev.app.cwm.com/'];

/** Signs in through the SPA flow and returns the cookies the browser would keep, by name. */
function rmLogin(User $user, array $extra = []): array
{
    // Each login is a different browser: no guard, session attributes or queued cookies from the previous one.
    app('auth')->forgetGuards();
    app()->forgetInstance('session.store');
    app('session')->forgetDrivers();
    app('cookie')->flushQueuedCookies();
    test()->flushSession();

    test()->withHeaders(RM_HEADERS)->get('/sanctum/csrf-cookie');
    $response = test()->withHeaders(RM_HEADERS)->postJson('/api/v1/app/login', array_merge([
        'email' => $user->email, 'password' => 'OriginalPass1', 'device' => 'x',
    ], $extra));

    return ['response' => $response, 'cookies' => collect($response->headers->getCookies())->keyBy(fn ($c) => $c->getName())->all()];
}

/** A GET as a browser that kept only the given cookies (the rest were dropped: closed browser, expired session). */
function rmAsBrowser(array $cookies, string $uri = '/api/v1/app/user')
{
    app('auth')->forgetGuards();
    app()->forgetInstance('session.store');
    app('session')->forgetDrivers(); // a fresh browser has no session attributes either
    app('cookie')->flushQueuedCookies();

    // The browser sends back exactly what the server set (encrypted); the middleware decrypts it.
    $raw = collect($cookies)->mapWithKeys(fn ($c) => [$c->getName() => $c->getValue()])->all();
    $server = ['HTTP_ORIGIN' => 'http://dev.app.cwm.com', 'HTTP_REFERER' => 'http://dev.app.cwm.com/', 'HTTP_ACCEPT' => 'application/json'];

    return test()->call('GET', $uri, [], $raw, [], $server);
}

function rmRecaller(array $cookies)
{
    return collect($cookies)->first(fn ($c) => str_starts_with($c->getName(), 'remember_web_'));
}

function rmSessionCookie(array $cookies)
{
    return collect($cookies)->first(fn ($c) => ! str_starts_with($c->getName(), 'remember_web_') && ! str_contains($c->getName(), 'XSRF'));
}

it('sets the long-lived recaller cookie only when remember is true', function () {
    $user = User::factory()->create(['password' => 'OriginalPass1', 'email_verified_at' => now()]);
    $with = rmLogin($user, ['remember' => true]);
    $with['response']->assertOk();

    $recaller = rmRecaller($with['cookies']);
    expect($recaller)->not->toBeNull()
        ->and($recaller->isHttpOnly())->toBeTrue()
        ->and($recaller->getSameSite())->toBe('lax')
        ->and($recaller->getExpiresTime())->toBeGreaterThan(now()->addDays(300)->timestamp)
        ->and($user->fresh()->remember_token)->not->toBeEmpty();

    $session = rmSessionCookie($with['cookies']);
    expect($session->isHttpOnly())->toBeTrue()
        ->and($session->getExpiresTime())->toBeLessThan(now()->addMinutes(125)->timestamp);

    $other = User::factory()->create(['password' => 'OriginalPass1', 'email_verified_at' => now()]);
    $without = rmLogin($other, ['remember' => false]);
    $without['response']->assertOk();
    expect(rmRecaller($without['cookies']))->toBeNull();
});

it('keeps the user signed in without the session cookie only when remembered', function () {
    $user = User::factory()->create(['password' => 'OriginalPass1', 'email_verified_at' => now()]);
    $remembered = rmLogin($user, ['remember' => true]);
    rmAsBrowser([rmRecaller($remembered['cookies'])])->assertOk()->assertJsonPath('data.user.email', $user->email);

    $other = User::factory()->create(['password' => 'OriginalPass1', 'email_verified_at' => now()]);
    $plain = rmLogin($other, ['remember' => false]);
    // The browser was closed / the 120 min session expired: nothing identifies the user any more.
    rmAsBrowser([])->assertUnauthorized();
    expect(rmRecaller($plain['cookies']))->toBeNull();
});

it('does not log the user in when the remember flag is the string "false"', function () {
    $user = User::factory()->create(['password' => 'OriginalPass1', 'email_verified_at' => now()]);
    $result = rmLogin($user, ['remember' => 'false']);

    expect(rmRecaller($result['cookies']))->toBeNull();
});

it('rejects a remember value that is not a boolean', function () {
    $user = User::factory()->create(['password' => 'OriginalPass1', 'email_verified_at' => now()]);
    rmLogin($user, ['remember' => 'banana'])['response']->assertUnprocessable()->assertJsonValidationErrors('remember');
});

it('dies with the logout: the old recaller cookie no longer works', function () {
    $user = User::factory()->create(['password' => 'OriginalPass1', 'email_verified_at' => now()]);
    $login = rmLogin($user, ['remember' => true]);
    $oldRecaller = rmRecaller($login['cookies']);
    $oldToken = $user->fresh()->remember_token;

    $this->withHeaders(RM_HEADERS)->postJson('/api/v1/app/logout')->assertOk();

    expect($user->fresh()->remember_token)->not->toBe($oldToken);
    rmAsBrowser([$oldRecaller])->assertUnauthorized();
});

it('invalidates the remembered cookie of other devices when the password changes', function () {
    $user = User::factory()->create(['password' => 'OriginalPass1', 'email_verified_at' => now()]);
    $login = rmLogin($user, ['remember' => true]);
    $stolen = rmRecaller($login['cookies']);

    // Same device, still in session: change the password.
    $this->withHeaders(RM_HEADERS)->postJson('/api/v1/app/user/update-password', [
        'currentPassword' => 'OriginalPass1', 'newPassword' => 'BrandNewPass9', 'newPasswordConfirmation' => 'BrandNewPass9',
    ])->assertOk();

    // The session in use stays (the app does not sign out the person who just changed it).
    app('auth')->forgetGuards();
    $this->withHeaders(RM_HEADERS)->getJson('/api/v1/app/user')->assertOk();

    // Another device that only holds the recaller cookie is out.
    $r = rmAsBrowser([$stolen]);
    $r->assertUnauthorized();
});

it('invalidates the remembered cookie when the password is reset', function () {
    $user = User::factory()->create(['password' => 'OriginalPass1', 'email_verified_at' => now()]);
    $recaller = rmRecaller(rmLogin($user, ['remember' => true])['cookies']);

    $user->forceFill(['password' => 'Another1Pass', 'remember_token' => Str::random(60)])->save();

    rmAsBrowser([$recaller])->assertUnauthorized();
});

it('blocks a remembered suspended account', function () {
    $user = User::factory()->create(['password' => 'OriginalPass1', 'email_verified_at' => now()]);
    $recaller = rmRecaller(rmLogin($user, ['remember' => true])['cookies']);

    $user->forceFill(['suspended_at' => now()])->save();

    rmAsBrowser([$recaller])->assertStatus(403)->assertJsonPath('code', 'account_suspended');
});

it('does not sign in a deleted account through the recaller cookie', function () {
    $user = User::factory()->create(['password' => 'OriginalPass1', 'email_verified_at' => now()]);
    $recaller = rmRecaller(rmLogin($user, ['remember' => true])['cookies']);

    $user->delete();

    rmAsBrowser([$recaller])->assertUnauthorized();
    expect(Auth::guard('web')->check())->toBeFalse();
});

it('gives the recaller cookie the same Secure flag and domain as the session cookie (staging: .quinvite.com)', function () {
    config(['session.secure' => true, 'session.domain' => '.quinvite.com']);
    $user = User::factory()->create(['password' => 'OriginalPass1', 'email_verified_at' => now()]);
    $cookies = rmLogin($user, ['remember' => true])['cookies'];

    foreach ([rmRecaller($cookies), rmSessionCookie($cookies)] as $cookie) {
        expect($cookie->isSecure())->toBeTrue()
            ->and($cookie->getDomain())->toBe('.quinvite.com')
            ->and($cookie->isHttpOnly())->toBeTrue()
            ->and($cookie->getSameSite())->toBe('lax');
    }
});

it('does not use two factor on the app login, so remember has nothing to bypass', function () {
    expect(file_get_contents(app_path('Http/Controllers/AuthenticationController.php')))->not->toContain('two_factor');
});
