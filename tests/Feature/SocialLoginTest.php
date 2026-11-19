<?php

use App\Events\UserLoggedInEvent;
use App\Events\UserRegistered;
use App\Models\SocialAccount;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    config(['app.spa_url' => 'http://spa.test']);
    RateLimiter::clear('oauth');
});

function fakeProvider(string $name, ?SocialiteUser $user = null, ?Throwable $fails = null): void
{
    $driver = Mockery::mock(Provider::class);
    if ($fails) {
        $driver->shouldReceive('user')->andThrow($fails);
    } elseif ($user) {
        $driver->shouldReceive('user')->andReturn($user);
    }
    $driver->shouldReceive('redirect')->andReturn(redirect()->away('https://provider.test/auth'));
    Socialite::shouldReceive('driver')->with($name)->andReturn($driver);
}

function socialUser(string $id, ?string $email, ?bool $emailVerified = null): SocialiteUser
{
    $raw = ['id' => $id, 'email' => $email];
    if ($emailVerified !== null) {
        $raw['email_verified'] = $emailVerified;
    }

    $user = (new SocialiteUser)->setRaw($raw)->map(['id' => $id, 'name' => 'Ana Social', 'email' => $email, 'avatar' => 'https://img.test/a.png']);
    $user->token = 'secret-access-token';
    $user->refreshToken = 'secret-refresh-token';

    return $user;
}

function callbackQuery($response): array
{
    parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

    return $query;
}

it('creates a verified appUser account for Google with a verified e-mail', function () {
    Event::fake([UserLoggedInEvent::class, UserRegistered::class]);
    fakeProvider('google', socialUser('g-1', 'new@example.com', true));

    $response = $this->get('/oauth/google/callback');

    expect(callbackQuery($response))->toBe(['status' => 'ok', 'provider' => 'google']);
    expect($response->headers->get('Location'))->toStartWith('http://spa.test/auth/social/callback?');
    $user = User::where('email', 'new@example.com')->first();
    expect($user->email_verified_at)->not->toBeNull()
        ->and($user->has_password)->toBeFalse()
        ->and($user->hasRole('appUser'))->toBeTrue()
        ->and($user->is_first_login)->toBeFalse()
        ->and($user->socialAccounts()->count())->toBe(1);
    $this->assertAuthenticatedAs($user);
    Event::assertDispatched(UserLoggedInEvent::class);
    Event::assertNotDispatched(UserRegistered::class);
});

it('creates an unverified account for Facebook and sends the confirmation e-mail', function () {
    Event::fake([UserLoggedInEvent::class, UserRegistered::class]);
    fakeProvider('facebook', socialUser('f-1', 'fb@example.com'));

    $response = $this->get('/oauth/facebook/callback');

    expect(callbackQuery($response))->toBe(['status' => 'ok', 'provider' => 'facebook']);
    $user = User::where('email', 'fb@example.com')->first();
    expect($user->email_verified_at)->toBeNull()->and($user->hasRole('appUser'))->toBeTrue();
    Event::assertDispatched(UserRegistered::class);
});

it('does not trust a Google payload without email_verified', function () {
    fakeProvider('google', socialUser('g-2', 'noflag@example.com'));

    $this->get('/oauth/google/callback');

    expect(User::where('email', 'noflag@example.com')->first()->email_verified_at)->toBeNull();
});

it('links Google with a verified e-mail to an existing verified account', function () {
    $existing = User::factory()->create(['email' => 'owner@example.com', 'email_verified_at' => now(), 'password' => 'KnownPass123']);
    fakeProvider('google', socialUser('g-3', 'Owner@Example.com', true));

    $response = $this->get('/oauth/google/callback');

    expect(callbackQuery($response)['status'])->toBe('ok');
    expect($existing->socialAccounts()->count())->toBe(1)
        ->and(Hash::check('KnownPass123', $existing->fresh()->password))->toBeTrue();
    $this->assertAuthenticatedAs($existing);
});

it('kills the password and tokens of an unverified account before linking it (pre-hijacking)', function () {
    $squatted = User::factory()->create(['email' => 'victim@example.com', 'email_verified_at' => null, 'password' => 'AttackerPass1']);
    $squatted->forceFill(['has_password' => true])->save();
    $squatted->createToken('attacker');
    fakeProvider('google', socialUser('g-4', 'victim@example.com', true));

    $response = $this->get('/oauth/google/callback');

    expect(callbackQuery($response)['status'])->toBe('ok');
    $fresh = $squatted->fresh();
    expect(Hash::check('AttackerPass1', $fresh->password))->toBeFalse()
        ->and($fresh->has_password)->toBeFalse()
        ->and($fresh->email_verified_at)->not->toBeNull()
        ->and($fresh->tokens()->count())->toBe(0)
        ->and($fresh->socialAccounts()->count())->toBe(1);
});

it('does not link Facebook to an existing account', function () {
    $existing = User::factory()->create(['email' => 'taken@example.com', 'email_verified_at' => now()]);
    fakeProvider('facebook', socialUser('f-2', 'taken@example.com'));

    $response = $this->get('/oauth/facebook/callback');

    expect(callbackQuery($response))->toBe(['status' => 'error', 'provider' => 'facebook', 'reason' => 'account_exists']);
    expect($existing->socialAccounts()->count())->toBe(0);
    $this->assertGuest();
});

it('does not link Google when the provider did not verify the e-mail', function () {
    $existing = User::factory()->create(['email' => 'taken2@example.com', 'email_verified_at' => null, 'password' => 'OriginalPass1']);
    fakeProvider('google', socialUser('g-5', 'taken2@example.com', false));

    $response = $this->get('/oauth/google/callback');

    expect(callbackQuery($response)['reason'])->toBe('account_exists');
    expect($existing->socialAccounts()->count())->toBe(0)
        ->and(Hash::check('OriginalPass1', $existing->fresh()->password))->toBeTrue();
    $this->assertGuest();
});

it('rejects suspended users, by social account and by linkable e-mail', function () {
    $linked = User::factory()->create(['suspended_at' => now()]);
    SocialAccount::create(['user_id' => $linked->id, 'provider' => 'google', 'provider_user_id' => 'g-6']);
    fakeProvider('google', socialUser('g-6', $linked->email, true));
    expect(callbackQuery($this->get('/oauth/google/callback'))['reason'])->toBe('account_suspended');

    $other = User::factory()->create(['suspended_at' => now(), 'email_verified_at' => now()]);
    fakeProvider('google', socialUser('g-7', $other->email, true));
    expect(callbackQuery($this->get('/oauth/google/callback'))['reason'])->toBe('account_suspended');
    $this->assertGuest();
});

it('signs in an already linked account', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    SocialAccount::create(['user_id' => $user->id, 'provider' => 'facebook', 'provider_user_id' => 'f-8']);
    fakeProvider('facebook', socialUser('f-8', null));

    expect(callbackQuery($this->get('/oauth/facebook/callback'))['status'])->toBe('ok');
    $this->assertAuthenticatedAs($user);
});

it('asks for an e-mail when the provider returns none', function () {
    fakeProvider('facebook', socialUser('f-9', null));

    expect(callbackQuery($this->get('/oauth/facebook/callback')))->toBe(['status' => 'error', 'provider' => 'facebook', 'reason' => 'email_required']);
    $this->assertGuest();
});

it('reports a cancellation as access_denied without logging', function () {
    Log::spy();

    $response = $this->get('/oauth/google/callback?error=access_denied&error_description=secret');

    expect(callbackQuery($response))->toBe(['status' => 'error', 'provider' => 'google', 'reason' => 'access_denied']);
    Log::shouldNotHaveReceived('warning');
});

it('logs real failures without tokens or e-mail', function () {
    Log::spy();
    fakeProvider('google', null, new RuntimeException('bad token ya29.SECRET for leak@example.com'));

    $response = $this->get('/oauth/google/callback');

    expect(callbackQuery($response)['reason'])->toBe('oauth_failed');
    Log::shouldHaveReceived('warning')->once()->withArgs(function ($message, $context) {
        $dump = json_encode([$message, $context]);

        return $context['provider'] === 'google' && $context['reason'] === 'oauth_failed'
            && ! str_contains($dump, 'ya29') && ! str_contains($dump, 'leak@example.com');
    });
});

it('treats other provider errors as oauth_failed', function () {
    expect(callbackQuery($this->get('/oauth/facebook/callback?error=server_error'))['reason'])->toBe('oauth_failed');
});

it('keeps a valid redirect in the session and returns it from the callback', function () {
    fakeProvider('google', socialUser('g-10', 'r@example.com', true));

    $redirect = $this->get('/oauth/google/redirect?redirect=' . urlencode('/dashboard/events?x=1'));
    $redirect->assertRedirect('https://provider.test/auth');
    expect($redirect->headers->get('Location'))->not->toContain('dashboard');

    $response = $this->get('/oauth/google/callback');

    expect(callbackQuery($response)['redirect'])->toBe('/dashboard/events?x=1');
});

it('also returns the redirect on errors and uses it once', function () {
    fakeProvider('google');
    $this->get('/oauth/google/redirect?redirect=/dashboard');

    $first = $this->get('/oauth/google/callback?error=access_denied');
    $second = $this->get('/oauth/google/callback?error=access_denied');

    expect(callbackQuery($first)['redirect'])->toBe('/dashboard')
        ->and(callbackQuery($second))->not->toHaveKey('redirect');
});

it('ignores redirects that are not internal paths', function (string $bad) {
    fakeProvider('google', socialUser('g-11', 'bad@example.com', true));

    $this->get('/oauth/google/redirect?redirect=' . urlencode($bad));
    $response = $this->get('/oauth/google/callback');

    expect(callbackQuery($response))->not->toHaveKey('redirect')->and(callbackQuery($response)['status'])->toBe('ok');
})->with([
    'protocol relative' => ['//evil.com'],
    'absolute' => ['https://evil.com/x'],
    'javascript' => ['javascript:alert(1)'],
    'relative' => ['dashboard'],
    'backslash' => ['/\\evil.com'],
    'control char' => ["/a\nb"],
    'too long' => ['/' . str_repeat('a', 200)],
]);

function exhaustOauthThrottle(): void
{
    for ($i = 0; $i < 20; $i++) {
        test()->get('/oauth/google/callback?error=access_denied')->assertRedirect();
    }
}

it('sends a throttled browser back to the SPA with reason=rate_limited', function () {
    exhaustOauthThrottle();

    $response = $this->get('/oauth/google/callback?error=access_denied')->assertRedirect();

    expect($response->headers->get('Location'))->toStartWith('http://spa.test/auth/social/callback?');
    expect(callbackQuery($response))->toBe(['status' => 'error', 'provider' => 'google', 'reason' => 'rate_limited']);
});

it('throttles the redirect route and keeps the internal redirect of the query', function () {
    exhaustOauthThrottle();

    $response = $this->get('/oauth/facebook/redirect?redirect=/dashboard')->assertRedirect();

    expect(callbackQuery($response))->toBe(['status' => 'error', 'provider' => 'facebook', 'reason' => 'rate_limited', 'redirect' => '/dashboard']);
});

it('keeps the internal redirect stored in the session when the callback is throttled', function () {
    exhaustOauthThrottle();

    $response = $this->withSession(['oauth.redirect' => '/events/1'])->get('/oauth/google/callback')->assertRedirect();

    expect(callbackQuery($response)['redirect'])->toBe('/events/1');
});

it('ignores an external redirect when the callback is throttled', function () {
    exhaustOauthThrottle();

    $response = $this->withSession(['oauth.redirect' => 'https://evil.test'])->get('/oauth/google/callback?redirect=//evil.test')->assertRedirect();

    expect(callbackQuery($response))->not->toHaveKey('redirect');
    expect($response->headers->get('Location'))->toStartWith('http://spa.test/');
});

it('keeps the JSON 429 for clients that ask for JSON', function () {
    exhaustOauthThrottle();

    $this->getJson('/oauth/google/callback')->assertStatus(429)->assertJson(['code' => 'too_many_attempts']);
});

it('answers 404 for an unknown provider, throttled or not', function () {
    exhaustOauthThrottle();

    $this->get('/oauth/github/callback')->assertNotFound();
});

it('shares the post-login behaviour with the password login', function () {
    $user = User::factory()->create(['email_verified_at' => now(), 'is_first_login' => true]);
    SocialAccount::create(['user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => 'g-12']);
    fakeProvider('google', socialUser('g-12', $user->email, true));

    $this->get('/oauth/google/callback');

    expect($user->fresh()->is_first_login)->toBeFalse();
});

it('uses a single default for the SPA url', function () {
    expect(file_get_contents(base_path('config/app.php')))->not->toContain("'https://celebrateitwithme.com'");
});
