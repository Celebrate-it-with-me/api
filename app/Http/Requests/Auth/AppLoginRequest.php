<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class AppLoginRequest extends FormRequest
{
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
