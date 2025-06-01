<?php

namespace App\Http\Requests\app;

use App\Rules\ColorRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;

class UpdateSaveTheDateRequest extends FormRequest
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
        Log::info('request from std', [$this->all()]);

        return [
            'stdTitle' => 'required|string|max:255',
            'stdSubTitle' => 'nullable|string|max:255',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'backgroundColor' => ['nullable', 'string', new ColorRule],
            'useCountdown' => 'required|boolean',
            'useAddToCalendar' => 'required|boolean',
            'isEnabled' => 'required|boolean',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'useCountdown' => $this->convertToBoolean($this->input('useCountdown')),
            'useAddToCalendar' => $this->convertToBoolean($this->input('useAddToCalendar')),
            'isEnabled' => $this->convertToBoolean($this->input('isEnabled')),
        ]);
    }

    private function convertToBoolean($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }
}
