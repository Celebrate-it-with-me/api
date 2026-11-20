<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery as Middleware;

class PreventRequestForgery extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        // Public invitation RSVP: authenticated by the guest code, no cookies or session involved.
        // CSRF protects nothing here and would break browsers that block cross-site cookies
        // (Safari, WhatsApp in-app browser).
        'api/v1/app/invitation/*/*/rsvp',
        // Public collaborator-invite decline: authenticated by the token in the URL, no session involved.
        // (Accept is NOT exempt: it needs a logged-in user.)
        'api/v1/app/event/*/collaborators/invite/*/decline',
    ];
}
