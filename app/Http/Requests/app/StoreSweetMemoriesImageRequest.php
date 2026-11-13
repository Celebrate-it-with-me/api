<?php

namespace App\Http\Requests\app;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;

class StoreSweetMemoriesImageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'files' => ['required', 'array', 'max:20'],
            // Real images only, up to 5 MB each (the screen already promises 5 MB).
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
