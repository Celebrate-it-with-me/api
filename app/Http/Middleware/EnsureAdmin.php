<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The admin panel's routes are for the staff role only. Being logged in is not enough. */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->hasRole('admin'), 403, 'This area is for administrators.');

        return $next($request);
    }
}
