<?php

namespace App\Http\Requests\app;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;

class StoreSaveTheDateRequest extends FormRequest
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
            'useCountdown' => filter_var($this->useCountdown, FILTER_VALIDATE_BOOLEAN),
            'useAddToCalendar' => filter_var($this->useAddToCalendar, FILTER_VALIDATE_BOOLEAN),
            'isEnabled' => filter_var($this->isEnabled, FILTER_VALIDATE_BOOLEAN),
        ]);
    }
    
    
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        Log::info('request from std', [$this->all()]);
        
        return [
            'stdTitle' => 'required|string|max:255', // Required string with a max length of 255
            'stdSubTitle' => 'nullable|string|max:255', // Optional string with a max length of 255
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048', // Optional image file with specific MIME types and max size of 2MB
            'backgroundColor' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/', // Optional string, must be a valid hex color code
            'useCountdown' => 'required|boolean', // Required, must be true/false
            'useAddToCalendar' => 'required|boolean', // Required, must be true/false
            'isEnabled' => 'required|boolean', // Required, must be true/false
        ];
        
    }
}
