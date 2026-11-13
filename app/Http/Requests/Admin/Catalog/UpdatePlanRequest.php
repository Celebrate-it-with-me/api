<?php

namespace App\Http\Requests\Admin\Catalog;

use App\Http\Services\Admin\AdminAudit;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route middleware (admin.can:admin.catalog.manage) decides
    }

    public function rules(): array
    {
        return [
            'maxGuests' => ['sometimes', 'required', 'integer', 'min:0', 'max:100000'],
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'reason' => AdminAudit::reasonRule(),
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            if (! $this->hasAny(['maxGuests', 'name', 'description'])) {
                $validator->errors()->add('maxGuests', 'Indica al menos un campo para cambiar.');
            }
        }];
    }
}
