<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class AppRegisterRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users',
                // Rows created before the lowercase rule may keep capitals: compare without case.
                function (string $attribute, mixed $value, Closure $fail) {
                    if (User::query()->whereRaw('lower(email) = ?', [mb_strtolower((string) $value)])->exists()) {
                        $fail(__('validation.unique', ['attribute' => $attribute]));
                    }
                },
            ],
            'password' => ['required', 'string', 'min:8'],
        ];
    }
}
