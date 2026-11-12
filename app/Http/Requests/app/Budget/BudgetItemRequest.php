<?php

namespace App\Http\Requests\app\Budget;

use App\Models\BudgetCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** An item of the budget: create and update take the same fields. */
class BudgetItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            // Only a category of this event's own budget.
            'categoryId' => ['nullable', 'integer', Rule::exists(BudgetCategory::class, 'id')->where('event_budget_id', $this->route('event')->budget?->id)],
            'description' => ['nullable', 'string', 'max:1000'],
            'vendorName' => ['nullable', 'string', 'max:120'],
            'vendorPhone' => ['nullable', 'string', 'max:30'],
            'estimatedCost' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'agreedCost' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'dueDate' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /** Column names for the model. @return array<string, mixed> */
    public function item(): array
    {
        $d = $this->validated();
        $blank = fn (string $key) => ($d[$key] ?? null) === '' ? null : ($d[$key] ?? null);

        return [
            'title' => trim($d['title']),
            'category_id' => $d['categoryId'] ?? null,
            'description' => $blank('description'),
            'vendor_name' => $blank('vendorName'),
            'vendor_phone' => $blank('vendorPhone'),
            'estimated_cost' => $d['estimatedCost'],
            'agreed_cost' => $d['agreedCost'] ?? null,
            'due_date' => $d['dueDate'] ?? null,
        ];
    }
}
