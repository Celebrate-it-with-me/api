<?php

namespace App\Http\Requests\app;

use Illuminate\Foundation\Http\FormRequest;

class StoreSuggestedVoteMusicRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'direction' => 'required|string|in:up,down',
            'accessCode' => 'required|string|exists:guests,code',
        ];
    }

    public function messages(): array
    {
        return [
            'direction.required' => 'Vote direction is required.',
            'direction.in' => 'Vote direction must be either "up" or "down".',
            'accessCode.required' => 'Access code is required.',
            'accessCode.exists' => 'Invalid access code.',
        ];
    }
}
