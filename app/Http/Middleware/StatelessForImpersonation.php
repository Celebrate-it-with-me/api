<?php

namespace App\Http\Middleware;

use App\Http\Services\Admin\Impersonation\ImpersonationService;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Sanctum treats a request from the SPA's origin as stateful: it starts the session and demands the CSRF token.
 * A support session lives in its own tab with a bearer token and must not touch (or be mixed up with) the session
 * cookie of the same person's normal app session, so a request carrying an impersonation token is NOT stateful:
 * no session, no cookie auth, no CSRF (the CSRF check protects cookie sessions, which this request does not use).
 */
class StatelessForImpersonation extends EnsureFrontendRequestsAreStateful
{
    public static function fromFrontend($request)
    {
        if (($bearer = $request->bearerToken()) && ImpersonationService::isImpersonationToken(PersonalAccessToken::findToken($bearer))) {
            return false;
        }

        return parent::fromFrontend($request);
    }
}
