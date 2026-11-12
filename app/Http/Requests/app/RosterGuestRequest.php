<?php

namespace App\Http\Requests\app;

use Illuminate\Foundation\Http\FormRequest;

/** A guest or companion typed by the organizer: the same fields to create and to edit. */
class RosterGuestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:255'],
            // Digits with an optional leading + and the usual separators; E.164 normalisation comes with WhatsApp.
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9][0-9 ().-]{5,19}$/'],
            'gender' => ['nullable', 'in:male,female,other'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, mixed> */
    public function person(): array
    {
        $data = $this->validated();

        return [
            'name' => trim($data['name']),
            'email' => ($data['email'] ?? null) ?: null,
            'phone' => ($data['phone'] ?? null) ? preg_replace('/\s+/', ' ', trim($data['phone'])) : null,
            'gender' => $data['gender'] ?? null,
            'notes' => ($data['notes'] ?? null) ?: null,
        ];
    }
}
