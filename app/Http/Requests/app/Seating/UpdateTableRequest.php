<?php

namespace App\Http\Requests\app\Seating;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTableRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $table = $this->route('table');
        return true/*$this->user()->can('manage', $table->event)*/;
    }
    
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:50'],
            'capacity' => [
                'sometimes',
                'required',
                'integer',
                'min:1',
                'max:50',
                // Capacity cannot be less than currently assigned guests
                function ($attribute, $value, $fail) {
                    $table = $this->route('table');
                    $assignedCount = $table->assignments()->count();
                    
                    if ($value < $assignedCount) {
                        $fail("Cannot reduce capacity below currently assigned guests ($assignedCount).");
                    }
                },
            ],
            'type' => ['sometimes', 'required', Rule::in(['vip', 'family', 'friends', 'general'])],
            'priority' => ['sometimes', 'required', 'integer', 'min:1', 'max:10'],
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
}
