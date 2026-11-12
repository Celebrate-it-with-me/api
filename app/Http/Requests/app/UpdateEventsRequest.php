<?php

namespace App\Http\Requests\app;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEventsRequest extends FormRequest
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
            // A hidden type is accepted only if the event already has it.
            'eventType' => [
                'sometimes',
                'integer',
                Rule::exists('event_types', 'id')->where(
                    fn ($q) => $q->where('is_active', true)
                        ->orWhere('id', $this->route('event')?->event_type_id)
                ),
            ],
        ];
    }
}
