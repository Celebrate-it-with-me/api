<?php

namespace App\Http\Requests\app;

use Illuminate\Foundation\Http\FormRequest;

class StoreSweetMemoryRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }
    
    public function prepareForValidation(): void
    {
        $this->merge([
            'visible' => filter_var($this->input('visible'), FILTER_VALIDATE_BOOLEAN),
        ]);
    }
    

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'year' => 'nullable|string|max:255',
            'visible' => 'boolean',
            'image' => 'nullable|array',
        ];
    }
}
