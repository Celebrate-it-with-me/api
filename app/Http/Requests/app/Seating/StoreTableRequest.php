<?php

namespace App\Http\Requests\app\Seating;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTableRequest extends FormRequest
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
            'event_id' => ['required', 'exists:events,id'],
            'name' => ['required', 'string', 'max:50'],
            'capacity' => ['required', 'integer', 'min:1', 'max:50'],
            'type' => ['required', Rule::in(['vip', 'family', 'friends', 'general'])],
            'priority' => ['required', 'integer', 'min:1', 'max:10'],
            'reserved_for' => ['nullable', 'string', 'max:100'],
            'location' => ['nullable', Rule::in(['front', 'center', 'back', 'side', 'entrance'])],
        ];
    }
    
    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Table name is required.',
            'name.max' => 'Table name cannot exceed 50 characters.',
            'capacity.required' => 'Table capacity is required.',
            'capacity.min' => 'Table must have at least 1 seat.',
            'capacity.max' => 'Table cannot have more than 50 seats.',
            'type.required' => 'Table type is required.',
            'type.in' => 'Invalid table type. Must be: vip, family, friends, or general.',
            'priority.required' => 'Table priority is required.',
            'priority.min' => 'Priority must be at least 1.',
            'priority.max' => 'Priority cannot exceed 10.',
            'location.in' => 'Invalid location. Must be: front, center, back, side, or entrance.',
        ];
    }
    
    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'event_id' => $this->route('event')->id,
        ]);
    }
}
