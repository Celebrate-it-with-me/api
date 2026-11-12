<?php

namespace App\Http\Services\AppServices;

use App\Exceptions\PlanLimitExceeded;
use App\Models\Events;

/**
 * The guest limit of an event's plan (`event_plans.max_guests`; 0 means no limit). It counts people:
 * the main guests and their companions, named or not.
 *
 * The rule (docs/plans/plans-and-modules.md): the list is loaded freely, and the limit is applied
 *  - when the invitation is PUBLISHED: a list over the limit blocks publishing;
 *  - once it is published: a hard cap, so nobody can publish at 30 and then load 170 more.
 * The API is what enforces it; the screens only explain it.
 */
class GuestLimit
{
    /** The limit, or null when the plan has none. */
    public function limitFor(Events $event): ?int
    {
        $max = (int) ($event->eventPlan?->max_guests ?? 0);

        return $max > 0 ? $max : null;
    }

    /** People in the event: main guests plus companions. */
    public function totalFor(Events $event): int
    {
        return $event->guests()->count();
    }

    /** Publishing is blocked while the list is over the limit. */
    public function assertCanPublish(Events $event): void
    {
        $limit = $this->limitFor($event);
        $total = $this->totalFor($event);

        if ($limit !== null && $total > $limit) {
            throw new PlanLimitExceeded(PlanLimitExceeded::GUEST_LIMIT, $limit, $total);
        }
    }

    /** Once the event is published, adding people past the limit is not allowed. In a draft the list is free. */
    public function assertCanAdd(Events $event, int $adding = 1): void
    {
        $limit = $this->limitFor($event);

        if ($limit === null || $event->status !== 'published') {
            return;
        }

        $total = $this->totalFor($event) + $adding;

        if ($total > $limit) {
            throw new PlanLimitExceeded(PlanLimitExceeded::GUEST_LIMIT, $limit, $total);
        }
    }
}
