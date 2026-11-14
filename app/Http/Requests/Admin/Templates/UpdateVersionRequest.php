<?php

namespace App\Http\Requests\Admin\Templates;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route middleware (admin.can:admin.templates.manage) decides
    }

    /** The content itself is checked by TemplateSchema (field-level errors); here only what may be sent. */
    public function rules(): array
    {
        return [
            'sections' => ['sometimes', 'array'],
            'theme' => ['sometimes', 'array'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:' . (int) config('invitation.limits.notes')],
        ];
    }

    public function after(): array
    {
        return [fn ($validator) => $this->hasAny(['sections', 'theme', 'notes']) || $validator->errors()->add('sections', 'Envía al menos sections, theme o notes.')];
    }
}
