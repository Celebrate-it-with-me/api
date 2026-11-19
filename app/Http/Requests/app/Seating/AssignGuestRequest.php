<?php

namespace App\Http\Requests\app\Seating;

use Illuminate\Validation\Rule;

/** Table fullness and "already seated" are decided inside the locked transaction (TableServices), with stable codes. */
class AssignGuestRequest extends SeatingRequest
{
    public function rules(): array
    {
        $table = $this->route('table');

        return [
            'guest_id' => [
                'bail',
                'required',
                'integer',
                Rule::exists('guests', 'id')->where('event_id', $table->event_id)->whereNull('deleted_at'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'guest_id.required' => __('seating.validation.guest_id_required'),
            'guest_id.integer' => __('seating.validation.guest_id_integer'),
            'guest_id.exists' => __('seating.validation.guest_id_exists'),
        ];
    }
}
