<?php

namespace App\Http\Requests\Admin\Catalog;

use App\Http\Services\Admin\AdminAudit;
use Illuminate\Foundation\Http\FormRequest;

class ToggleModuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route middleware (admin.can:admin.catalog.manage) decides
    }

    public function rules(): array
    {
        return [
            'isEnabled' => ['required', 'boolean'],
            'reason' => AdminAudit::reasonRule(),
        ];
    }
}
