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
        ];
    }
}
