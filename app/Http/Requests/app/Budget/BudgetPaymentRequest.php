<?php

namespace App\Http\Requests\app\Budget;

use Illuminate\Foundation\Http\FormRequest;

/** A payment towards an item. `padrinoId` says who paid it: empty is the family. */
class BudgetPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'paidOn' => ['required', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:160'],
            'padrinoId' => ['nullable', 'integer'],
        ];
    }

    /** Column names for the model. @return array<string, mixed> */
    public function payment(): array
    {
        $d = $this->validated();

        return [
            'amount' => $d['amount'],
            'paid_on' => $d['paidOn'],
            'note' => ($d['note'] ?? null) ?: null,
            'padrino_id' => $d['padrinoId'] ?? null,
        ];
    }
}
