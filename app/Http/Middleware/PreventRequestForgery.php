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

        // Public code gate of a link without guest code: no session, nobody is logged in; the 5/min, 30/h and per-slug limits are the protection.
        'api/v1/app/invitation/*/access/verify',
    ];
}
