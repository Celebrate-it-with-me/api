<?php

namespace App\Http\Requests\app;

use Illuminate\Foundation\Http\FormRequest;

class StoreRsvpRequest extends FormRequest
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
            'eventId' => 'required|int',
            'title' => 'required|string',
            'description' => 'string',
            'customFields' => 'array',
            'confirmationDeadline' => 'required|date',
        ];
    }
}
