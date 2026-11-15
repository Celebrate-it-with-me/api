<?php

namespace App\Http\Requests\app;

use Illuminate\Contracts\Validation\ValidationRule;
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
    
    public function prepareForValidation(): void
    {
        $this->merge([
           'autoplay' => filter_var($this->input('autoplay'), FILTER_VALIDATE_BOOLEAN)
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'iconSize' => 'required|string',
            'iconPosition' => 'required|string',
            'iconColor' => 'required|string',
            'autoplay' => 'required|boolean',
            'songFile' => ['required', 'file', 'mimetypes:audio/mpeg,audio/mp3,audio/wav,audio/x-wav,audio/ogg,application/ogg,audio/mp4,audio/x-m4a,audio/aac', 'max:10240'],
        ];
    }
}
