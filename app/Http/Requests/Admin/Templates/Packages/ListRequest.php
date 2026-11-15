<?php

namespace App\Http\Requests\Admin\Templates\Packages;

use App\Models\TemplatePackage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::in([TemplatePackage::INSTALLED, TemplatePackage::ACTIVE, TemplatePackage::DEACTIVATED, TemplatePackage::WITHDRAWN])],
            'visibility' => ['nullable', Rule::in([TemplatePackage::PUBLIC, TemplatePackage::PRIVATE])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return ['*' => 'invalid'];
    }
}
