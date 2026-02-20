<?php

namespace App\Http\Requests\app\Seating;

use App\Models\Guest;
use App\Models\Seating\TableAssignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignGuestRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $table = $this->route('table');
        return /*$this->user()->can('manage', $table->event)*/true;
    }
    
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'guest_id' => [
                'required',
                'integer',
                'exists:guests,id',
                // Guest must belong to the same event
                function ($attribute, $value, $fail) {
                    $table = $this->route('table');
                    $guest = Guest::find($value);
                    
                    if ($guest && $guest->event_id !== $table->event_id) {
                        $fail('Guest does not belong to this event.');
                    }
                },
                // Guest cannot already be assigned to a table
                function ($attribute, $value, $fail) {
                    $exists = TableAssignment::query()->where('guest_id', $value)->exists();
                    
                    if ($exists) {
                        $fail('Guest is already assigned to a table.');
                    }
                },
                // Table must have available seats
                function ($attribute, $value, $fail) {
                    $table = $this->route('table');
                    
                    if (!$table->hasAvailableSeats()) {
                        $fail('Table is full.');
                    }
                },
            ],
        ];
    }
    
    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'guest_id.required' => 'Guest ID is required.',
            'guest_id.integer' => 'Guest ID must be an integer.',
            'guest_id.exists' => 'Guest not found.',
        ];
    }
}
