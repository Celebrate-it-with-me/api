<?php

namespace App\Http\Requests\Admin\Events;

use App\Http\Services\Admin\AdminAudit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ModuleOverrideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route middleware (admin.can:admin.modules.gift) decides
    }

    public function rules(): array
    {
        if ($this->isMethod('DELETE')) {
            return ['reason' => AdminAudit::reasonRule()];
        }

        return [
            'state' => ['required', Rule::in(['active', 'locked'])],
            'expiresAt' => ['nullable', 'date', 'after:now'],
            'reason' => AdminAudit::reasonRule(),
        ];
    }
}
