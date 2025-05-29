<?php

namespace App\Http\Services\Budget;

class EventBudgetServices
{
    /**
     * Get the budget for a given event.
     *
     * @param $event
     * @return mixed
     */
    public function getEventBudget($event): mixed
    {
        return $event->budget;
    }
    
    /**
     * Get the budget items for a given event.
     *
     * @param $event
     * @param $budgetData
     * @return mixed
     */
    public function createEventBudget($event, $budgetData): mixed
    {
        $event->budget()->updateOrCreate(
            ['event_id' => $event->id],
            $budgetData
        );
        
        return $event->budget;
    }
}
