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
            'subTitle' => 'string|max:255',
            'mainColor' => 'required|string|max:255',
            'secondaryColor' => 'required|string|max:255',
            'usePreview' => 'required|boolean',
            'useSuggestedMusic' => 'required|boolean',
            'useVoteSystem' => 'required|boolean',
            'searchLimit' => 'numeric',
        ];
    }
}
