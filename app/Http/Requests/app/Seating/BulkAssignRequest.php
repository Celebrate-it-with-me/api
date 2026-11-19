<?php

namespace App\Http\Requests\app\Seating;

use App\Models\Guest;
use App\Models\Seating\Table;
use Illuminate\Validation\Rule;

class BulkAssignRequest extends SeatingRequest
{
    public function rules(): array
    {
        $event = $this->event();

        return [
            'assignments' => ['required', 'array', 'min:1', 'max:200'],
            'assignments.*.table_id' => ['bail', 'required', 'integer', Rule::exists(Table::class, 'id')->where('event_id', $event->id)],
            'assignments.*.guest_ids' => ['bail', 'required', 'array', 'min:1', 'max:500'],
            'assignments.*.guest_ids.*' => ['bail', 'required', 'integer', Rule::exists(Guest::class, 'id')->where('event_id', $event->id)->whereNull('deleted_at')],
        ];
    }

    public function messages(): array
    {
        return [
            'assignments.*.guest_ids.*.exists' => __('seating.validation.guest_id_exists'),
            'assignments.*.guest_ids.*.integer' => __('seating.validation.guest_id_integer'),
            'assignments.*.table_id.exists' => __('seating.validation.to_table_id_exists'),
        ];
    }
}
