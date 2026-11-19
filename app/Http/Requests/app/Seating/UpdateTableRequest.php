<?php

namespace App\Http\Requests\app\Seating;

use Illuminate\Validation\Rule;

class UpdateTableRequest extends SeatingRequest
{
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
                        $fail(__('seating.validation.capacity_below_assigned', ['count' => $assignedCount]));
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
        $m = fn (string $key) => __("seating.validation.$key");

        return [
            'name.required' => $m('name_required'),
            'name.max' => $m('name_max'),
            'capacity.required' => $m('capacity_required'),
            'capacity.min' => $m('capacity_min'),
            'capacity.max' => $m('capacity_max'),
            'type.required' => $m('type_required'),
            'type.in' => $m('type_in'),
            'priority.required' => $m('priority_required'),
            'priority.min' => $m('priority_min'),
            'priority.max' => $m('priority_max'),
            'reserved_for.max' => $m('reserved_for_max'),
            'location.in' => $m('location_in'),
        ];
    }
}
