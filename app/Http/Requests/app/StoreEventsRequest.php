<?php

namespace App\Http\Requests\app;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEventsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * An event is born as a draft: the status is not an input here (see `EventLifecycleService`).
     * The end date and the description are optional.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'eventName' => 'required|string|max:255',
            'eventType' => ['required', 'integer', Rule::exists('event_types', 'id')->where('is_active', true)],
            'eventDescription' => 'nullable|string|max:5000',
            'startDate' => 'required|date_format:m/d/Y H:i',
            'endDate' => 'nullable|date_format:m/d/Y H:i|after_or_equal:startDate',
            'customUrlSlug' => ['nullable', 'string', 'max:60', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'unique:events,custom_url_slug'],
        ];
    }
}
