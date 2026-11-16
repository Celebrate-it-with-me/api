<?php

namespace App\Http\Requests\Admin\Templates\Packages;

use App\Http\Services\Admin\AdminAudit;
use Illuminate\Foundation\Http\FormRequest;

/** The reason every sensitive package action carries (5-500 characters), plus the marks acknowledgement on activation. */
class ReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => AdminAudit::reasonRule(),
            'ack_third_party_marks' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['*.required' => 'required', '*' => 'invalid'];
    }
}
