<?php

namespace App\Http\Requests\app\Budget;

use Illuminate\Foundation\Http\FormRequest;

/** The overall cap of the budget; empty means "no cap". */
class UpdateBudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['cap' => ['present', 'nullable', 'numeric', 'min:0', 'max:99999999']];
    }
}
