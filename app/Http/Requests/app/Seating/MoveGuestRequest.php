<?php

namespace App\Http\Requests\app\Seating;

use Illuminate\Validation\Rule;

class MoveGuestRequest extends SeatingRequest
{
    public function rules(): array
    {
        $event = $this->event();

        return [
            'guest_id' => ['bail', 'required', 'integer', Rule::exists('guests', 'id')->where('event_id', $event->id)->whereNull('deleted_at')],
            'to_table_id' => ['bail', 'required', 'integer', Rule::exists('tables', 'id')->where('event_id', $event->id)],
        ];
    }

    public function messages(): array
    {
        return [
            'guest_id.required' => __('seating.validation.guest_id_required'),
            'guest_id.integer' => __('seating.validation.guest_id_integer'),
            'guest_id.exists' => __('seating.validation.guest_id_exists'),
            'to_table_id.required' => __('seating.validation.to_table_id_required'),
            'to_table_id.integer' => __('seating.validation.to_table_id_exists'),
            'to_table_id.exists' => __('seating.validation.to_table_id_exists'),
        ];
    }
}
