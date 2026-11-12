<?php

namespace App\Http\Requests\app\Padrinos;

use App\Models\BudgetItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The full set of items a padrino promised to cover, each with an amount. */
class CommitmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $budgetId = $this->route('event')->budget?->id;

        return [
            'commitments' => ['present', 'array', 'max:60'],
            'commitments.*.budgetItemId' => ['required', 'integer', 'distinct', Rule::exists(BudgetItem::class, 'id')->where('event_budget_id', $budgetId)],
            'commitments.*.amount' => ['required', 'numeric', 'gt:0', 'max:99999999'],
        ];
    }
}
