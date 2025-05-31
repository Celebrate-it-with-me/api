<?php

namespace App\Http\Services\Budget;

use App\Models\BudgetItem;
use App\Models\EventBudget;
use Illuminate\Support\Collection;

class BudgetItemServices
{
    /**
     * Get all budget items for a given event.
     *
     * @param $eventBudget
     * @return Collection
     */
    public function getBudgetItems($eventBudget): Collection
    {
        return $eventBudget->items()
            ->with(['category'])
            ->orderBy('category_id')
            ->get();
    }
    
    /**
     * Create a new budget item for the given event budget.
     *
     * @param array $data
     * @param EventBudget $eventBudget
     * @return BudgetItem
     */
    public function createBudgetItem(array $data, EventBudget $eventBudget): BudgetItem
    {
        return $eventBudget->items()->create([
            'category_id' => $data['categoryId'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'estimated_cost' => $data['estimatedCost'],
            'actual_cost' => $data['actualCost'] ?? null,
            'is_paid' => $data['isPaid'] ?? false,
            'due_date' => $data['dueDate'] ?? null,
        ]);
    }
    
    /**
     * Update an existing budget item.
     *
     * @param BudgetItem $budgetItem
     * @param array $data
     * @return BudgetItem
     */
    public function updateBudgetItem(BudgetItem $budgetItem, array $data): BudgetItem
    {
        $budgetItem->update([
            'category_id' => $data['categoryId'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'estimated_cost' => $data['estimatedCost'],
            'actual_cost' => $data['actualCost'] ?? null,
            'is_paid' => $data['isPaid'],
            'due_date' => $data['dueDate'] ?? null,
        ]);
        
        return $budgetItem;
    }
    
}
