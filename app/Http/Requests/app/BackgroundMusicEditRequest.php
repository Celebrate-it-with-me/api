<?php

namespace App\Http\Requests\app;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class BackgroundMusicEditRequest extends FormRequest
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
     * @return array<string, ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'iconSize' => 'required|string',
            'iconPosition' => 'required|string',
            'iconColor' => 'required|string',
            'autoplay' => 'required|boolean',
            'songFile' => 'required',
        ];
    }
}
