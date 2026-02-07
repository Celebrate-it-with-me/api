<?php

namespace App\Http\Requests\Theme;

use Illuminate\Foundation\Http\FormRequest;

class UpdateThemeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'theme_config' => 'required|array',
            'theme_config.global' => 'sometimes|array',
            'theme_config.global.colors' => 'sometimes|array',
            'theme_config.global.typography' => 'sometimes|array',
            'theme_config.sections' => 'sometimes|array',
        ];
    }

    public function messages(): array
    {
        return [
            'theme_config.required' => 'Theme configuration is required',
            'theme_config.array' => 'Theme configuration must be a valid structure',
        ];
    }
}
