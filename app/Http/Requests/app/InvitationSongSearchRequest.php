<?php

namespace App\Http\Requests\app;

use App\Http\Services\AppServices\Invitation\InvitationSongs;
use Illuminate\Foundation\Http\FormRequest;

class InvitationSongSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['bail', 'required', 'string', 'max:100'],
            'limit' => ['bail', 'nullable', 'integer', 'min:1', 'max:' . InvitationSongs::SEARCH_MAX_LIMIT],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'q.required' => 'required',
            'q.string' => 'invalid',
            'q.max' => 'too_long',
            'limit.integer' => 'invalid',
            'limit.min' => 'invalid',
            'limit.max' => 'invalid',
        ];
    }

    protected function prepareForValidation(): void
    {
        $q = $this->query('q');

        if (is_string($q)) {
            $this->merge(['q' => trim($q)]);
        }
    }
}
