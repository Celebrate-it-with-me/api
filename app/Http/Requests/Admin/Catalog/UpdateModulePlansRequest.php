<?php

namespace App\Http\Requests\Admin\Catalog;

use App\Http\Services\Admin\AdminAudit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateModulePlansRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route middleware (admin.can:admin.catalog.manage) decides
    }

    public function rules(): array
    {
        return [
            'planSlugs' => ['present', 'array'],
            'planSlugs.*' => ['string', 'distinct', Rule::exists('event_plans', 'slug')],
            'keepForExisting' => ['sometimes', 'boolean'],
            'reason' => AdminAudit::reasonRule(),
        ];
    }
}
