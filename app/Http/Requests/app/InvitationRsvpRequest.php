<?php

namespace App\Http\Requests\app;

use App\Http\Services\AppServices\InvitationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InvitationRsvpRequest extends FormRequest
{
    /**
     * Bidi controls (LRM, RLM, LRE..RLO, LRI..PDI) can reverse text on screen and in exports, and zero-width characters
     * (ZWSP, ZWNJ, ZWJ, word joiner, BOM) make two names look identical: refused in free text. Note that ZWJ also
     * glues emoji sequences, so a family or flag-with-ZWJ emoji is refused too.
     */
    private const BIDI = '\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}\x{2066}-\x{2069}\x{FEFF}';

    /** A name: no control characters at all (not even a line break), no direction controls. */
    private const NAME_PATTERN = '/^[^\p{Cc}' . self::BIDI . ']+$/u';

    /** A note: plain text where only line breaks (LF, CR) are allowed among the control characters. */
    private const NOTE_PATTERN = '/^[^\x00-\x09\x0B\x0C\x0E-\x1F\x7F-\x9F' . self::BIDI . ']*$/u';

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
            'companions.*.name' => ['sometimes', 'bail', 'required', 'string', 'min:2', 'max:120', 'regex:' . self::NAME_PATTERN],
            'note' => ['sometimes', 'nullable', 'string', 'max:500', 'regex:' . self::NOTE_PATTERN],
        ];
    }

    /** Codes, not sentences: the invitation writes the text in the guest's language. @return array<string, string> */
    public function messages(): array
    {
        return [
            'note.max' => 'too_long',
            'note.string' => 'invalid_characters',
            'note.regex' => 'invalid_characters',
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
