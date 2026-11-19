<?php

namespace App\Http\Requests\app\Seating;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class StoreTableRequest extends SeatingRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
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
