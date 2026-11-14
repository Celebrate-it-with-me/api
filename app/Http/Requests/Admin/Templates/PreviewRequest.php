<?php

namespace App\Http\Requests\Admin\Templates;

use Illuminate\Foundation\Http\FormRequest;

class PreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route middleware (admin.can:admin.templates.manage) decides
    }

    public function rules(): array
    {
        return [
            'sections' => ['required', 'array'],
            'theme' => ['required', 'array'],
            'locale' => ['required', 'in:es,en'],
            'eventType' => ['required', 'in:quinceanera,wedding'],
        ];
    }
}
