<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class AppLoginRequest extends FormRequest
{
    /** Addresses are stored and looked up in lowercase: PostgreSQL treats "Ana@x.com" and "ana@x.com" as different. */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'device' => 'required|string',
            // "Remember me": a real boolean (the SPA sends true/false); a string like "false" must not turn it on.
            'remember' => ['nullable', 'boolean'],
        ];
    }
}
