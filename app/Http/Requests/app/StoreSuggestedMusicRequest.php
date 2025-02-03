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
            'eventId' => 'required|numeric|exists:events,id',
            'name' => 'required|string|max:255',
            'platformUrl' => 'required|string|max:255',
            'suggestedBy' => 'required|numeric|exists:main_guests,id',
        ];
        
    }
}
