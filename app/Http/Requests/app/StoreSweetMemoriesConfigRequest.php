<?php

namespace App\Http\Requests\app;

use Illuminate\Foundation\Http\FormRequest;

class StoreSweetMemoriesConfigRequest extends FormRequest
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
            'title' => 'required|string',
            'subTitle' => 'nullable|string',
            'backgroundColor' => 'nullable|string',
            'maxPictures' => 'required|string',
        ];
    }
}
