<?php

namespace App\Http\Requests\app;

use App\Http\Services\AppServices\Invitation\InvitationComments;
use Illuminate\Foundation\Http\FormRequest;

class InvitationCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The guest code in the URL is the credential; it is checked in the controller.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Plain text: line breaks allowed, no other control characters, no direction controls (same as the RSVP note).
            'comment' => ['bail', 'required', 'string', 'max:' . InvitationComments::MAX_LENGTH, 'regex:' . InvitationRsvpRequest::NOTE_PATTERN],
        ];
    }

    /** Codes, not sentences: the invitation writes the text in the guest's language. @return array<string, string> */
    public function messages(): array
    {
        return [
            'comment.required' => 'required',
            'comment.string' => 'invalid_characters',
            'comment.max' => 'too_long',
            'comment.regex' => 'invalid_characters',
        ];
    }

    /** A comment of only blanks is no comment. */
    protected function prepareForValidation(): void
    {
        $comment = $this->input('comment');

        if (is_string($comment)) {
            $this->merge(['comment' => trim($comment)]);
        }
    }
}
