<?php

namespace App\Http\Services\AppServices;

use App\Models\EventPlan;
use App\Models\Events;

/**
 * What the event's plan unlocks beyond the free tier. Until the plans become free/full, "premium" is any plan
 * above the entry one (the lowest id, which every new event starts on).
 */
class PlanFeatures
{
    public function isPremium(Events $event): bool
    {
        return $event->event_plan_id !== null && (int) $event->event_plan_id !== $this->entryPlanId();
    }

    /** The entry plan every new event starts on (the lowest id; `basic` in the seeders). The single definition of it. */
    public function entryPlanId(): int
    {
        return (int) EventPlan::query()->orderBy('id')->value('id');
    }
}
