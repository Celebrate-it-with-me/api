<?php

namespace App\Http\Middleware;

use App\Http\Services\AppServices\EventModules;
use App\Http\Services\AppServices\InvitationService;
use App\Models\Events;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `module.access:<key>`: the API enforces the lock the interface shows (see EventModules).
 *
 * The module key is passed by each route, never listed here: the catalog decides what can be locked.
 * It resolves the event from the `{event}` parameter (id or bound model) or, on the public invitation,
 * from `{slug}`, and answers 403 `module_locked` when the module is locked for that event's plan
 * (staff exceptions included) or switched off by the admin.
 *
 * Put it AFTER `event.access` / `event.guest` so that who is not allowed to see the event still gets their
 * 404. If no event can be resolved it lets the request through: the controller answers its own 404.
 */
class EnsureModuleAccess
{
    public function __construct(private readonly EventModules $modules, private readonly InvitationService $invitations) {}

    public function handle(Request $request, Closure $next, string $module): Response
    {
        $event = $this->resolveEvent($request);

        if ($event && ! $this->modules->isActive($event, $module)) {
            return response()->json([
                'message' => 'This module is not available for this event.',
                'code' => 'module_locked',
                'module' => $module,
            ], 403);
        }

        return $next($request);
    }

    private function resolveEvent(Request $request): ?Events
    {
        $event = $request->route('event');

        if ($event instanceof Events) {
            return $event;
        }

        if ($event !== null) {
            return Events::query()->find($event);
        }

        // Routes without `{event}` in the address (e.g. renaming a memory image): the event comes from the bound model.
        // Someone who is not a member of that event learns nothing about it: the controller answers its 404.
        foreach ($request->route()->parameters() as $bound) {
            if ($bound instanceof Model && array_key_exists('event_id', $bound->getAttributes())) {
                $event = Events::query()->find($bound->getAttribute('event_id'));

                return $event && $request->user()?->hasEventRole($event) ? $event : null;
            }
        }

        $slug = $request->route('slug');

        if (! is_string($slug)) {
            return null;
        }

        // Public invitation: slug + guest code are the credentials. Unknown slug, unpublished event and wrong code
        // all answer the same 404 as the controller, before anything is said about the module.
        $event = $this->invitations->findEvent($slug);
        $code = $request->route('code');
        $guest = $event && is_string($code) ? $this->invitations->findGuest($event, $code) : $event;

        abort_if(! $event || ! $guest, 404, 'Invitation not found.');

        return $event;
    }
}
