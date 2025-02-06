<?php

namespace App\Http\Requests\app;

use Illuminate\Foundation\Http\FormRequest;

class StoreSuggestedMusicConfigRequest extends FormRequest
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
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => 'string|max:255',
            'sub_title' => 'string|max:255',
            'main_color' => 'required|string|max:255',
            'secondary_color' => 'required|string|max:255',
            'use_preview' => 'required|boolean',
            'use_vote_system' => 'required|boolean',
            'search_limit' => 'numeric',
        ];
    }
}
