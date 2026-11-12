<?php

namespace App\Http\Requests\app;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Partial update of an event (`PATCH`, and `PUT` for older clients): every field is optional and only
 * what is sent is changed. Status and plan are not editable here.
 */
class UpdateEventsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $event = $this->route('event');

        return [
            'eventName' => 'sometimes|required|string|max:255',
            // A hidden type is accepted only if the event already has it.
            'eventType' => [
                'sometimes',
                'integer',
                Rule::exists('event_types', 'id')->where(
                    fn ($q) => $q->where('is_active', true)->orWhere('id', $event?->event_type_id)
                ),
            ],
            'eventDescription' => 'sometimes|nullable|string|max:5000',
            'startDate' => 'sometimes|required|date_format:m/d/Y H:i',
            'endDate' => 'sometimes|nullable|date_format:m/d/Y H:i',
            'customUrlSlug' => [
                'sometimes',
                'string',
                'max:60',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('events', 'custom_url_slug')->ignore($event?->id),
            ],
            'saveTheDate' => 'sometimes|boolean',
            'rsvp' => 'sometimes|boolean',
            'menu' => 'sometimes|boolean',
            'sweetMemories' => 'sometimes|boolean',
            'music' => 'sometimes|boolean',
            'backgroundMusic' => 'sometimes|boolean',
            'eventComments' => 'sometimes|boolean',
            'location' => 'sometimes|boolean',
            'seatsAccommodation' => 'sometimes|boolean',
            'preview' => 'sometimes|boolean',
            'budget' => 'sometimes|boolean',
            'eventBudget' => 'sometimes|boolean',
            'analytics' => 'sometimes|boolean',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $event = $this->route('event');

            // Once published, the public address is fixed: the links already sent depend on it.
            if ($this->filled('customUrlSlug') && $event?->isSlugLocked() && $this->input('customUrlSlug') !== $event->custom_url_slug) {
                $validator->errors()->add('customUrlSlug', 'The public address cannot change once the event has been published.');
            }

            // The end cannot come before the start (the one sent, or else the saved one).
            if ($this->filled('endDate') && ! $validator->errors()->hasAny(['startDate', 'endDate'])) {
                $start = $this->filled('startDate')
                    ? Carbon::createFromFormat('m/d/Y H:i', $this->input('startDate'))
                    : $event?->start_date;
                $end = Carbon::createFromFormat('m/d/Y H:i', $this->input('endDate'));

                if ($start && $end->lt($start)) {
                    $validator->errors()->add('endDate', 'The end date must not be before the start date.');
                }
            }
        }];
    }
}
