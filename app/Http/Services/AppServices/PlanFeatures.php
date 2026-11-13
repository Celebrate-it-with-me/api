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
        $entry = EventPlan::query()->orderBy('id')->value('id');

        return $event->event_plan_id !== null && (int) $event->event_plan_id !== (int) $entry;
    }
}
