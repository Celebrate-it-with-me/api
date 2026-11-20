<?php

namespace App\Http\Requests\app;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGuestRequest extends FormRequest
{
    /** Per guest and per kind (named, unnamed): the service creates one row per companion in a loop. */
    public const MAX_COMPANIONS = 50;

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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'guest' => ['required', 'array'],
            'guest.name' => ['required', 'string', 'max:255'],
            'guest.email' => ['nullable', 'email', 'max:255'],
            'guest.phone' => ['nullable', 'string', 'max:20'],
            'guest.gender' => ['nullable', 'string', 'in:male,female,other'],
            // A menu of THIS event only: an id of another event's menu must not be assignable.
            'guest.menuSelected' => ['nullable', 'integer', Rule::exists('menus', 'id')->where('event_id', $this->eventId())],

            'namedCompanions' => ['nullable', 'array', 'max:' . self::MAX_COMPANIONS],
            'namedCompanions.*.name' => ['required_with:namedCompanions', 'string', 'max:255'],
            'namedCompanions.*.email' => ['nullable', 'email', 'max:255'],
            'namedCompanions.*.phone' => ['nullable', 'string', 'max:20'],
            'namedCompanions.*.gender' => ['nullable', 'string', 'in:male,female,other'],

            'unnamedCompanions' => ['nullable', 'integer', 'min:0', 'max:' . self::MAX_COMPANIONS],

            'preferences' => ['nullable', 'array'],
            'preferences.meal_preference' => ['nullable', 'string', 'max:255'],
            'preferences.allergies' => ['nullable', 'string', 'max:255'],
            'preferences.notes' => ['nullable', 'string'],
        ];
    }

    private function eventId(): mixed
    {
        $event = $this->route('event');

        return is_object($event) ? $event->getKey() : $event;
    }
}
