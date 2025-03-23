<?php

namespace App\Http\Requests\app;

use Illuminate\Foundation\Http\FormRequest;

class BackgroundMusicRequest extends FormRequest
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
            'iconSize' => 'required|string',
            'iconPosition' => 'required|string',
            'iconColor' => 'required|string',
            'autoplay' => 'required|boolean',
            'songFile' => 'required|mimes:mp3,wav,ogg|max:10240',
        ];
    }
}
