<?php

namespace App\Http\Requests\app;

use Illuminate\Foundation\Http\FormRequest;

class StorePublicSuggestedMusicRequest extends FormRequest
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
            'title' => 'required|string|max:255',
            'artist' => 'required|string|max:255',
            'album' => 'required|string|max:255',
            'platformId' => 'required|string|max:255',
            'thumbnailUrl' => 'required|string|max:500',
            'accessCode' => 'required|string|exists:guests,code', // Must be valid guest code
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
            'accessCode.required' => 'Access code is required.',
            'accessCode.exists' => 'Invalid access code.',
        ];
    }
}
