<?php

namespace App\Http\Requests\app;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class InvitationAccessVerifyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['code' => ['required', 'string', 'max:40']];
    }

    /** A missing or malformed code answers the very same 422 as a wrong one. */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => __('errors.invitation_access.invalid_code'),
            'code' => 'invalid_code',
        ], 422));
    }
}
