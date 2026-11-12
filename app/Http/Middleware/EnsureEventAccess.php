<?php

namespace App\Http\Middleware;

use App\Models\Events;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards every route under `/event/{event}`.
 *
 * - Not a member of the event: 404 (the same answer as for an event that does not exist,
 *   so ids cannot be probed).
 * - Member without the needed permission: 403.
 *
 * - Any other model in the URL that carries an `event_id` must belong to this same event, so a
 *   member of event A cannot reach (or delete) a guest of event B through `/event/A/guests/{B's guest}`.
 *
 * Without a parameter the permission follows the HTTP method: reading needs `view_event`,
 * anything that changes data needs `edit_event`. Sensitive routes name their own permission,
 * e.g. `event.access:delete_event`.
 */
class EnsureEventAccess
{
    private const READ_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function handle(Request $request, Closure $next, ?string $permission = null): Response
    {
        $user = $request->user();
        $event = $request->route('event');

        if (! $event instanceof Events) {
            $event = Events::query()->find($event);
        }

        if (! $user || ! $event || ! $user->hasEventRole($event)) {
            abort(404);
        }

        foreach ($request->route()->parameters() as $name => $bound) {
            if ($name !== 'event' && $bound instanceof Model && array_key_exists('event_id', $bound->getAttributes())
                && (int) $bound->getAttribute('event_id') !== (int) $event->id) {
                abort(404);
            }
        }

        // The two child models without an `event_id` hang from a parent that was checked above.
        $params = $request->route()->parameters();
        if (isset($params['menuItem'], $params['menu']) && (int) $params['menuItem']->menu_id !== (int) $params['menu']->id) {
            abort(404);
        }
        if (isset($params['budgetItem'], $params['eventBudget'])
            && (int) $params['budgetItem']->event_budget_id !== (int) $params['eventBudget']->id) {
            abort(404);
        }

        $needed = $permission ?? (in_array($request->method(), self::READ_METHODS, true) ? 'view_event' : 'edit_event');

        if (! in_array($needed, $user->getEventPermissions($event), true)) {
            abort(403, 'You do not have permission to do this in this event.');
        }

        return $next($request);
    }
}
