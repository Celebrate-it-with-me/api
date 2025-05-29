<?php

namespace App\Http\Resources\AppResources\Budget;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BudgetItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'eventBudgetId' => $this->event_budget_id,
            'categoryId' => $this->category_id,
            'title' => $this->title,
            'description' => $this->description,
            'estimatedCost' => $this->estimated_cost,
            'actualCost' => $this->actual_cost,
            'isPaid' => $this->is_paid,
            'dueDate' => $this->due_date?->format('Y-m-d'),
        ];
    }
}
