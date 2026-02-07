<?php

namespace App\Http\Requests\Theme;

use Illuminate\Foundation\Http\FormRequest;

class PublicRsvpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => 'required|string|in:attending,not_attending,maybe',
            'guests_count' => 'sometimes|integer|min:1|max:10',
            'notes' => 'sometimes|string|max:1000',
            'dietary_restrictions' => 'sometimes|string|max:500'
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'RSVP status is required',
            'status.in' => 'RSVP status must be attending, not_attending, or maybe',
            'guests_count.max' => 'Maximum 10 guests allowed',
            'notes.max' => 'Notes cannot exceed 1000 characters',
            'dietary_restrictions.max' => 'Dietary restrictions cannot exceed 500 characters'
        ];
    }
}
