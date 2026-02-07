<?php

namespace App\Http\Requests\app;

use Illuminate\Foundation\Http\FormRequest;

class StoreSuggestedMusicRequest extends FormRequest
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
            'title' => 'required|string|max:255',
            'artist' => 'required|string|max:255',
            'album' => 'required|string|max:255',
            'platformId' => 'required|string|max:255',
            'thumbnailUrl' => 'required|string|max:500',
            'previewUrl' => 'nullable|string|max:500',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Song title is required.',
            'artist.required' => 'Artist name is required.',
            'album.required' => 'Album name is required.',
            'platformId.required' => 'Spotify ID is required.',
            'thumbnailUrl.required' => 'Song thumbnail is required.',
            'previewUrl.required' => 'Song preview is required.',
        ];
    }
}
