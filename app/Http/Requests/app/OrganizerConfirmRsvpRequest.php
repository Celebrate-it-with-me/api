<?php

namespace App\Http\Requests\app;

use Illuminate\Foundation\Http\FormRequest;

class OrganizerConfirmRsvpRequest extends FormRequest
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
            'guest' => 'required|array',
            'guest.rsvp_status' => 'required|in:attending,not-attending',
            'guest.notes' => 'nullable|string|max:100',

            'companions' => 'sometimes|array',
            'companions.*.id' => 'required|integer|exists:guests,id',
            'companions.*.rsvp_status' => 'required|in:attending,not-attending',
            'companions.*.notes' => 'nullable|string|max:100',
        ];
    }
}
