<?php

namespace App\Http\Requests\Admin\Templates;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route middleware (admin.can:admin.templates.manage) decides
    }

    public function rules(): array
    {
        // The content comes from the request, or from a package (a staged upload or an installed one).
        $fromPackage = fn () => $this->filled('stage_token') || $this->filled('package_id');

        return [
            'sections' => [Rule::requiredIf(! $fromPackage()), 'array'],
            'theme' => [Rule::requiredIf(! $fromPackage()), 'array'],
            'locale' => ['required', 'in:es,en'],
            'eventType' => ['required', 'in:quinceanera,wedding'],
            'stage_token' => ['nullable', 'string', 'size:26', 'prohibits:package_id'],
            'package_id' => ['nullable', 'integer', 'min:1'],
            'preset' => ['nullable', 'string', 'max:32'],
            'state' => ['nullable', 'in:invitation,save_the_date,no_photo'],
        ];
    }
}
