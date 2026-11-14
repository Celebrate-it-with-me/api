<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class AdminTwoFactorLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'challenge' => ['required', 'string', 'max:100'],
            'device' => ['required', 'string', 'max:255'],
            // One of the two: the six digits of the app, or a recovery code.
            'code' => ['required_without:recoveryCode', 'nullable', 'string', 'max:20'],
            'recoveryCode' => ['required_without:code', 'nullable', 'string', 'max:30'],
        ];
    }
}
