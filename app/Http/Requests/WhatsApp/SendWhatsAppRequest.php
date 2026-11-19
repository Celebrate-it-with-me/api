<?php

namespace App\Http\Requests\WhatsApp;

use App\Support\WhatsApp\TemplateCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Permission is decided by `event.access` on the route. Error messages are the contract's codes. */
class SendWhatsAppRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'template' => ['required', Rule::in(TemplateCatalog::SENDABLE_KEYS)],
            'audience' => ['required', Rule::in(['all', 'pending', 'selected'])],
            'guest_ids' => ['nullable', 'array', 'max:500'],
            'guest_ids.*' => ['integer'],
            'force' => ['nullable', 'boolean'],
            'dry_run' => ['nullable', 'boolean'],
            'confirm_large' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'required', 'in' => 'invalid', 'array' => 'invalid', 'integer' => 'invalid', 'boolean' => 'invalid',
            'guest_ids.max' => 'too_many',
        ];
    }
}
