<?php

namespace App\Http\Middleware;

use App\Models\Events;
use App\Models\Guest;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the guest-facing routes under `template/event/{event}` (comments, suggested music, votes).
 *
 * The only credential is the guest's `accessCode`, which must belong to a guest of this very event,
 * and the event must be published. Anything else gets the same 404, whether the event exists or not,
 * so neither event ids nor codes can be probed. This applies to reads as well as writes.
 */
class EnsureGuestEventAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $event = $request->route('event');

        if (! $event instanceof Events) {
            $event = Events::query()->find($event);
        }

        $code = $request->input('accessCode');

        $valid = $event
            && $event->status === 'published'
            && is_string($code)
            && $code !== ''
            && Guest::query()->where('event_id', $event->id)->where('code', $code)->exists();

        if (! $valid) {
            abort(404);
        }

        return $next($request);
    }
}
