<?php

namespace App\Http\Controllers\AppControllers\Budget;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppResources\Budget\EventBudgetResource;
use App\Http\Services\Budget\EventBudgetServices;
use App\Models\EventBudget;
use App\Models\Events;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventBudgetController extends Controller
{
    public function __construct(private readonly EventBudgetServices $eventBudgetServices) {}
    
    /**
     * Display the budget for a specific event.
     *
     * @param Events $event
     * @return EventBudgetResource|JsonResponse
     */
    public function show(Events $event): EventBudgetResource|JsonResponse
    {
        try {
            $eventBudget = $this->eventBudgetServices->getEventBudget($event);
            if (!$eventBudget) {
                return response()->json(['message' => 'Budget not found for this event.']);
            }
            
            return EventBudgetResource::make($eventBudget);
            
        } catch (\Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }
    
    /**
     * Handles the storage of a new event budget. Validates incoming request data and creates
     * a budget for the specified event. Returns the created event budget as a resource or an
     * error response in case of failure.
     *
     * @param Request $request The incoming HTTP request containing the event budget data.
     * @param Events $event
     * @return EventBudgetResource|JsonResponse The created event budget resource or an error response.
     *
     */
    public function store(Request $request, Events $event): EventBudgetResource|JsonResponse
    {
        try {
            $data = $request->validate([
                'budgetCap' => 'required|numeric|min:0',
            ]);
            
            $eventBudget = $this->eventBudgetServices->createEventBudget($event, $data);
            if (!$eventBudget) {
                return response()->json(['message' => 'Failed to create budget for this event.'], 500);
            }
            
            return EventBudgetResource::make($eventBudget);
            
        } catch (\Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }
    
    /**
     * Update the budget for a specific event.
     *
     * @param Events $event
     * @param EventBudget $eventBudget
     * @return JsonResponse
     */
    public function destroy(Events $event, EventBudget $eventBudget): JsonResponse
    {
        try {
            $eventBudget->delete();
            return response()->json(['message' => 'Event budget deleted successfully.']);
        } catch (\Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }
}
