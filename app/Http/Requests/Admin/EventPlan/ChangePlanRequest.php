<?php

namespace App\Http\Requests\Admin\EventPlan;

use App\Http\Services\Admin\AdminAudit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'planSlug' => ['required', 'string', Rule::exists('event_plans', 'slug')],
            'reason' => AdminAudit::reasonRule(),
        ];
    }
}
