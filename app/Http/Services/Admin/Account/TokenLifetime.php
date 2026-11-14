<?php

namespace App\Http\Services\Admin\Account;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Idle and absolute expiry of the admin panel's tokens. Sanctum stamps `last_used_at` while authenticating, before
 * any middleware of ours runs, so the previous value is captured from the `TokenAuthenticated` event (see
 * AppServiceProvider) into a request attribute and read back here.
 */
class TokenLifetime
{
    public const ATTRIBUTE = 'admin.token_previous_use';

    public const IDLE = 'idle';

    public const ABSOLUTE = 'absolute';

    /** Called by the event listener, just before Sanctum overwrites `last_used_at`. */
    public static function remember(Request $request, PersonalAccessToken $token): void
    {
        $request->attributes->set(self::ATTRIBUTE, ['token' => $token->getKey(), 'at' => $token->last_used_at]);
    }

    /** Why this token is over (`idle`, `absolute`), or null while it is alive. */
    public function expiredReason(Request $request, PersonalAccessToken $token): ?string
    {
        $max = max(1, (int) config('admin.token_max_days'));
        $idle = max(1, (int) config('admin.token_idle_hours'));

        if ($token->created_at && $token->created_at->lte(now()->subDays($max))) {
            return self::ABSOLUTE;
        }

        $remembered = $request->attributes->get(self::ATTRIBUTE);
        $previous = is_array($remembered) && ($remembered['token'] ?? null) === $token->getKey() ? $remembered['at'] : $token->last_used_at;
        $reference = $previous instanceof CarbonInterface ? $previous : $token->created_at;

        if ($reference && $reference->lte(now()->subHours($idle))) {
            return self::IDLE;
        }

        return null;
    }
}
