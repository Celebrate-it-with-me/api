<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEventLocationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }
    
    protected function prepareForValidation(): void
    {
        $this->merge([
            'isDefault' => filter_var($this->isDefault, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
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
            'name' => 'required|string|max:255',
            'address' => 'required|string|max:255',
            'city' => 'required|string|max:255',
            'state' => 'required|string|max:255',
            'zipCode' => 'required|string|max:10',
            'country' => 'required|string|max:255',
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'isDefault' => 'nullable|boolean',
            'images.*' => 'nullable|image|max:2048',
            'google_photos' => 'nullable|string',
        ];
    }
}
