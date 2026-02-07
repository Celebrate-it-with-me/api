<?php

namespace App\Http\Services\Auth;

use App\Models\SocialAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthService
{
    public function redirect(string $provider): RedirectResponse
    {
        $this->assertProvider($provider);

        return Socialite::driver($provider)->redirect();
    }

    public function callback(string $provider): RedirectResponse
    {
        $this->assertProvider($provider);

        try {
            $socialUser = Socialite::driver($provider)->user();
        } catch (\Throwable $e) {
            return $this->redirectToSpa('error', $provider, 'oauth_failed');
        }

        $providerUserId = (string) $socialUser->getId();
        $email = $socialUser->getEmail(); // may be null (Facebook)
        $name = $socialUser->getName() ?: ($socialUser->getNickname() ?: 'User');
        $avatar = $socialUser->getAvatar();

        $account = SocialAccount::query()
            ->where('provider', $provider)
            ->where('provider_user_id', $providerUserId)
            ->first();

        if ($account?->user) {
            Auth::login($account->user);
            return $this->redirectToSpa('ok', $provider);
        }

        $user = $email
            ? User::query()->where('email', $email)->first()
            : null;

        if (!$user) {
            if (!$email) {
                return $this->redirectToSpa('error', $provider, 'email_required');
            }

            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => bcrypt(Str::random(32)),
            ]);
        }

        SocialAccount::query()->create([
            'user_id' => $user->id,
            'provider' => $provider,
            'provider_user_id' => $providerUserId,
            'provider_email' => $email,
            'avatar' => $avatar,
            'access_token' => $socialUser->token ?? null,
            'refresh_token' => $socialUser->refreshToken ?? null,
            'expires_at' => !empty($socialUser->expiresIn)
                ? CarbonImmutable::now()->addSeconds((int) $socialUser->expiresIn)
                : null,
        ]);

        Auth::login($user);

        return $this->redirectToSpa('ok', $provider);
    }

    private function assertProvider(string $provider): void
    {
        if (!in_array($provider, ['google', 'facebook'], true)) {
            abort(404);
        }
    }

    private function redirectToSpa(string $status, string $provider, ?string $reason = null): RedirectResponse
    {
        $base = rtrim(config('app.spa_url', 'https://app.celebrateitwithme.com'), '/');

        $query = [
            'status' => $status,
            'provider' => $provider,
        ];

        if ($reason) {
            $query['reason'] = $reason;
        }

        return redirect()->away($base . '/auth/social/callback?' . http_build_query($query));
    }
}
