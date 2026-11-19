<?php

namespace App\Http\Services\Auth;

use App\Events\UserRegistered;
use App\Models\SocialAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;

/**
 * Login with Google / Facebook. An existing account is linked ONLY when the provider vouches for the e-mail
 * (Google with email_verified=true); otherwise the owner must sign in with the password (reason=account_exists).
 */
class SocialAuthService
{
    private const SESSION_REDIRECT_KEY = 'oauth.redirect';

    private const REDIRECT_MAX_LENGTH = 200;

    public function __construct(private readonly PostLoginService $postLogin) {}

    public function redirect(Request $request, string $provider): RedirectResponse
    {
        $this->assertProvider($provider);

        // The wanted landing route lives in our session, never in the URL sent to the provider.
        $redirect = $this->safeRedirect($request->query('redirect'));
        if ($request->hasSession()) {
            $redirect
                ? $request->session()->put(self::SESSION_REDIRECT_KEY, $redirect)
                : $request->session()->forget(self::SESSION_REDIRECT_KEY);
        }

        return Socialite::driver($provider)->redirect();
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        $this->assertProvider($provider);

        $redirect = $request->hasSession() ? $this->safeRedirect($request->session()->pull(self::SESSION_REDIRECT_KEY)) : null;

        // The user pressed "cancel" at the provider: not a failure, nothing to log.
        if ($request->query('error') === 'access_denied') {
            return $this->redirectToSpa('error', $provider, 'access_denied', $redirect);
        }

        if ($request->query('error')) {
            return $this->fail($provider, 'oauth_failed', $redirect, 'provider_error');
        }

        try {
            $socialUser = Socialite::driver($provider)->user();
        } catch (\Throwable $e) {
            // Class name only: the message may carry tokens or the e-mail.
            return $this->fail($provider, 'oauth_failed', $redirect, $e::class);
        }

        $providerUserId = (string) $socialUser->getId();
        $email = $socialUser->getEmail(); // may be null (Facebook)
        $name = $socialUser->getName() ?: ($socialUser->getNickname() ?: 'User');
        $verified = $this->providerVerifiedEmail($provider, $socialUser);

        $account = SocialAccount::query()
            ->where('provider', $provider)
            ->where('provider_user_id', $providerUserId)
            ->first();

        if ($account?->user) {
            if ($account->user->suspended_at) {
                return $this->redirectToSpa('error', $provider, 'account_suspended', $redirect);
            }

            return $this->finish($request, $account->user, $provider, $redirect);
        }

        if (! $email) {
            return $this->redirectToSpa('error', $provider, 'email_required', $redirect);
        }

        $user = User::query()->whereRaw('LOWER(email) = ?', [Str::lower($email)])->first();

        if ($user) {
            if (! $verified) {
                return $this->redirectToSpa('error', $provider, 'account_exists', $redirect);
            }
            if ($user->suspended_at) {
                return $this->redirectToSpa('error', $provider, 'account_suspended', $redirect);
            }
            if (! $user->email_verified_at) {
                $this->neutralizeUnverifiedAccount($user);
            }
        } else {
            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make(Str::random(32)),
            ]);
            // Nobody knows this password: deleting the account will not ask for one.
            $user->forceFill(['has_password' => false])->save();
            // Load the column defaults (is_first_login...) so the post-login step sees them.
            $user->refresh();
            // Same global role as the normal registration.
            $user->assignRole('appUser');

            if (! $verified) {
                // Facebook never counts as verified: the usual confirmation e-mail goes out.
                event(new UserRegistered($user));
            }
        }

        if ($verified && ! $user->email_verified_at) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        SocialAccount::query()->create([
            'user_id' => $user->id,
            'provider' => $provider,
            'provider_user_id' => $providerUserId,
            'provider_email' => $email,
            'avatar' => $socialUser->getAvatar(),
            'access_token' => $socialUser->token ?? null,
            'refresh_token' => $socialUser->refreshToken ?? null,
            'expires_at' => ! empty($socialUser->expiresIn)
                ? CarbonImmutable::now()->addSeconds((int) $socialUser->expiresIn)
                : null,
        ]);

        return $this->finish($request, $user, $provider, $redirect);
    }

    /** Only Google can vouch for the address, and only when it says so. */
    private function providerVerifiedEmail(string $provider, SocialiteUser $socialUser): bool
    {
        if ($provider !== 'google') {
            return false;
        }

        $raw = method_exists($socialUser, 'getRaw') ? $socialUser->getRaw() : ($socialUser->user ?? []);
        $flag = $raw['email_verified'] ?? $raw['verified_email'] ?? false;

        return $flag === true || $flag === 'true' || $flag === 1 || $flag === '1';
    }

    /**
     * Pre-hijacking: somebody may have registered this address (unverified) with a password they know before the
     * real owner arrives with Google. Their password and every open session/token die before the account is linked.
     */
    private function neutralizeUnverifiedAccount(User $user): void
    {
        $user->forceFill([
            'password' => Hash::make(Str::random(40)),
            'has_password' => false,
            'remember_token' => Str::random(60),
        ])->save();

        $user->tokens()->delete();
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->getKey())->delete();
        }
    }

    private function finish(Request $request, User $user, string $provider, ?string $redirect): RedirectResponse
    {
        $this->postLogin->complete($user, $request, false);

        return $this->redirectToSpa('ok', $provider, null, $redirect);
    }

    private function fail(string $provider, string $reason, ?string $redirect, string $cause): RedirectResponse
    {
        // No tokens, no e-mail, no provider message: only what is needed to see that the integration is broken.
        Log::warning('Social login failed', ['provider' => $provider, 'reason' => $reason, 'cause' => $cause]);

        return $this->redirectToSpa('error', $provider, $reason, $redirect);
    }

    /**
     * The "oauth" throttle answers a browser navigation, so it must land in the SPA like every other OAuth failure.
     * The wanted route is read (not pulled) from the session or the query and only if it is an internal path.
     */
    public function rateLimitedRedirect(Request $request, string $provider): RedirectResponse
    {
        $redirect = $this->safeRedirect($request->query('redirect'))
            ?? ($request->hasSession() ? $this->safeRedirect($request->session()->get(self::SESSION_REDIRECT_KEY)) : null);

        return $this->redirectToSpa('error', $provider, 'rate_limited', $redirect);
    }

    /** Internal path only: starts with a single "/", no scheme, host, backslash or control characters. */
    private function safeRedirect(mixed $value): ?string
    {
        if (! is_string($value) || $value === '' || strlen($value) > self::REDIRECT_MAX_LENGTH) {
            return null;
        }
        if ($value[0] !== '/' || str_starts_with($value, '//') || preg_match('/[\\\\\x00-\x1f\x7f]/', $value)) {
            return null;
        }

        return $value;
    }

    private function assertProvider(string $provider): void
    {
        if (! in_array($provider, ['google', 'facebook'], true)) {
            abort(404);
        }
    }

    private function redirectToSpa(string $status, string $provider, ?string $reason = null, ?string $redirect = null): RedirectResponse
    {
        $query = ['status' => $status, 'provider' => $provider];

        if ($reason) {
            $query['reason'] = $reason;
        }
        if ($redirect) {
            $query['redirect'] = $redirect;
        }

        return redirect()->away(rtrim((string) config('app.spa_url'), '/') . '/auth/social/callback?' . http_build_query($query));
    }
}
