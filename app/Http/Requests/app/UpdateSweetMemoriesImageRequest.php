<?php

namespace App\Http\Requests\app;

use Illuminate\Foundation\Http\FormRequest;

/** Edit an image: its details, and optionally replace the file. A details-only edit needs no file. */
class UpdateSweetMemoriesImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'files' => ['nullable', 'array', 'max:1'],
            'files.*' => ['image', 'mimes:jpeg,png,webp,gif', 'max:5120'],
            'title' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:1000'],
            'year' => ['nullable', 'integer', 'between:1900,2100'],
            'visible' => ['nullable', 'boolean'],
            'order' => ['nullable', 'integer', 'min:0'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
