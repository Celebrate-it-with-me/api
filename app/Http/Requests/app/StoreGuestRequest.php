<?php

namespace App\Http\Requests\app;

use Illuminate\Foundation\Http\FormRequest;

class StoreGuestRequest extends FormRequest
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
            'guest' => ['required', 'array'],
            'guest.name' => ['required', 'string', 'max:255'],
            'guest.email' => ['nullable', 'email', 'max:255'],
            'guest.phone' => ['nullable', 'string', 'max:20'],

            'namedCompanions' => ['nullable', 'array'],
            'namedCompanions.*.name' => ['required_with:namedCompanions', 'string', 'max:255'],
            'namedCompanions.*.email' => ['nullable', 'email', 'max:255'],
            'namedCompanions.*.phone' => ['nullable', 'string', 'max:20'],

            'unnamedCompanions' => ['nullable', 'integer', 'min:0'],

            'preferences' => ['nullable', 'array'],
            'preferences.meal_preference' => ['nullable', 'string', 'max:255'],
            'preferences.allergies' => ['nullable', 'string', 'max:255'],
            'preferences.notes' => ['nullable', 'string'],
        ];
    }
}
