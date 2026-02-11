<?php

namespace App\Http\Requests\app;

use Illuminate\Foundation\Http\FormRequest;

class DestroyPublicSuggestedMusicRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'songId' => 'required|integer',
            'accessCode' => 'required|string|exists:guests,code',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'songId.required' => 'Song Id is required.',
            'accessCode.exists' => 'Invalid access code.',
        ];
    }
}
