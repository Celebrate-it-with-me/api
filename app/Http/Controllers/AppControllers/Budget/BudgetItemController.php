<?php

namespace App\Http\Controllers\AppControllers\Budget;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppResources\Budget\BudgetItemResource;
use App\Http\Services\Budget\BudgetItemServices;
use App\Models\BudgetItem;
use App\Models\EventBudget;
use App\Models\Events;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BudgetItemController extends Controller
{
    public function __construct(private readonly BudgetItemServices $budgetItemServices) {}
    
    /**
     * Display a listing of the resource.
     *
     * @param Events $event
     * @param EventBudget $eventBudget
     * @return JsonResponse|AnonymousResourceCollection
     */
    public function index(Events $event,EventBudget $eventBudget): JsonResponse | AnonymousResourceCollection
    {
        try {
            $budgetItems = $this->budgetItemServices->getBudgetItems($eventBudget);
            
            if (!$budgetItems->count()) {
                return response()->json(['message' => 'No budget items found for this event.']);
            }
            
            return BudgetItemResource::collection($budgetItems);
            
        } catch (\Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }
    
    /**
     * Store a newly created resource in storage.
     *
     * @param Request $request
     * @param Events $event
     * @param EventBudget $eventBudget
     * @return BudgetItemResource|JsonResponse
     */
    public function store(Request $request, Events $event, EventBudget $eventBudget): BudgetItemResource | JsonResponse
    {
        try {
            $data = $request->validate([
                'categoryId' => 'required|exists:budget_categories,id',
                'title' => 'required|string|max:255',
                'description' => 'nullable|string|max:1000',
                'estimatedCost' => 'required|numeric|min:0',
                'actualCost' => 'nullable|numeric|min:0',
                'isPaid' => 'nullable|boolean',
                'dueDate' => 'nullable|date',
            ]);
            
            $budgetItem = $this->budgetItemServices->createBudgetItem($data, $eventBudget) ?? null;
            
            if (!$budgetItem) {
                return response()->json(['message' => 'Failed to create budget item.'], 422);
            }
            
            return BudgetItemResource::make($budgetItem);
            
        } catch (\Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }
    
    /**
     * Update the specified resource in storage.
     * @param Request $request
     * @param Events $event
     * @param EventBudget $eventBudget
     * @param BudgetItem $budgetItem
     * @return BudgetItemResource|JsonResponse
     */
    public function update(Request $request, Events $event, EventBudget $eventBudget, BudgetItem $budgetItem): BudgetItemResource | JsonResponse
    {
        try {
            $data = $request->validate([
                'categoryId' => 'required|exists:budget_categories,id',
                'title' => 'required|string|max:255',
                'description' => 'nullable|string|max:1000',
                'estimatedCost' => 'required|numeric|min:0',
                'actualCost' => 'nullable|numeric|min:0',
                'isPaid' => 'required|boolean',
                'dueDate' => 'nullable|date',
            ]);
            
            $budgetItem = $this->budgetItemServices->updateBudgetItem($budgetItem, $data) ?? null;
            
            if (!$budgetItem) {
                return response()->json(['message' => 'Failed to update budget item.'], 422);
            }
            
            return BudgetItemResource::make($budgetItem);
            
        } catch (\Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }
    
    /**
     * Remove the specified resource from storage.
     *
     * @param Events $event
     * @param BudgetItem $budgetItem
     * @return BudgetItemResource|JsonResponse
     */
    public function destroy(Events $event, EventBudget $eventBudget, BudgetItem $budgetItem): BudgetItemResource | JsonResponse
    {
        try {
            $budgetItem->delete();
            
            return response()->json(['message' => 'Budget item deleted successfully.']);
        } catch (\Throwable $th) {
            return response()->json(['message' => $th->getMessage(), 'data' => []], 500);
        }
    }
    
    
}
