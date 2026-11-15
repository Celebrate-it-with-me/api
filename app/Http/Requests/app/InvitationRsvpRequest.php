<?php

namespace App\Http\Requests\app;

use App\Http\Services\AppServices\InvitationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InvitationRsvpRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The guest code in the URL is the credential; it is checked in the controller.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $status = Rule::in([InvitationService::STATUS_ATTENDING, InvitationService::STATUS_NOT_ATTENDING]);

        return [
            'guest' => ['required', 'array'],
            'guest.rsvpStatus' => ['required', 'string', $status],
            'companions' => ['sometimes', 'array', 'max:50'],
            'companions.*.id' => ['required', 'integer', 'distinct'],
            'companions.*.rsvpStatus' => ['required', 'string', $status],
            // Optional: a missing key leaves things as they are; a null/empty note clears it.
            'companions.*.name' => ['sometimes', 'bail', 'required', 'string', 'min:2', 'max:120', 'regex:/^[^\p{Cc}]+$/u'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    /** Codes, not sentences: the invitation writes the text in the guest's language. @return array<string, string> */
    public function messages(): array
    {
        return [
            'note.max' => 'too_long',
            'note.string' => 'invalid_characters',
            'companions.max' => 'invalid',
            'companions.array' => 'invalid',
            'companions.*.id.required' => 'invalid',
            'companions.*.id.integer' => 'invalid',
            'companions.*.id.distinct' => 'invalid',
            'companions.*.name.required' => 'required',
            'companions.*.name.string' => 'invalid_characters',
            'companions.*.name.min' => 'too_short',
            'companions.*.name.max' => 'too_long',
            'companions.*.name.regex' => 'invalid_characters',
        ];
    }
}
