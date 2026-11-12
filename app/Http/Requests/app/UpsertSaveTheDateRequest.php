<?php

namespace App\Http\Requests\app;

use Illuminate\Foundation\Http\FormRequest;

/** What the organizer chooses for the Save the date: a short message and two switches. */
class UpsertSaveTheDateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'message' => ['nullable', 'string', 'max:500'],
            'showCountdown' => ['required', 'boolean'],
            'useAddToCalendar' => ['required', 'boolean'],
        ];
    }

    /** Column names for the model. */
    public function settings(): array
    {
        $data = $this->validated();

        return [
            'message' => ($data['message'] ?? null) ? trim($data['message']) : null,
            'show_countdown' => (bool) $data['showCountdown'],
            'use_add_to_calendar' => (bool) $data['useAddToCalendar'],
        ];
    }
}
