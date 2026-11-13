<?php

use App\Http\Middleware\StatelessForImpersonation;
use App\Models\ImpersonationSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

function fromSpa(?string $bearer): Request
{
    $request = Request::create('/api/v1/app/user', 'POST', server: ['HTTP_ORIGIN' => 'http://dev.app.cwm.com', 'HTTP_REFERER' => 'http://dev.app.cwm.com/impersonate']);
    if ($bearer) {
        $request->headers->set('Authorization', 'Bearer ' . $bearer);
    }

    return $request;
}

// Laravel skips the CSRF check while testing, so this checks the decision itself: is the request "stateful".
it('treats a request from the SPA origin as stateful, unless it carries an impersonation token', function () {
    config(['sanctum.stateful' => ['dev.app.cwm.com']]);
    $user = User::factory()->create();
    $plain = $user->createToken('plain')->plainTextToken;
    $imp = $user->createToken('impersonation', [ImpersonationSession::ABILITY])->plainTextToken;

    expect(StatelessForImpersonation::fromFrontend(fromSpa(null)))->toBeTrue()
        ->and(StatelessForImpersonation::fromFrontend(fromSpa($plain)))->toBeTrue()
        ->and(StatelessForImpersonation::fromFrontend(fromSpa('garbage')))->toBeTrue()
        ->and(StatelessForImpersonation::fromFrontend(fromSpa($imp)))->toBeFalse();
});
