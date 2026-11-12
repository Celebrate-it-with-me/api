<?php

namespace App\Http\Requests\app\Timeline;

use App\Models\Timeline;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TimelineStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array|string> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(Timeline::TYPES)],
            'description' => ['nullable', 'string', 'max:500'],
            'startTime' => ['required', 'date_format:Y-m-d H:i:s'],
            'endTime' => ['nullable', 'date_format:Y-m-d H:i:s', 'after:startTime'],
        ];
    }

    /** Column names for the model. */
    public function moment(): array
    {
        $data = $this->validated();

        return [
            'title' => $data['title'],
            'type' => $data['type'],
            'description' => ($data['description'] ?? null) ?: null,
            'start_time' => $data['startTime'],
            'end_time' => $data['endTime'] ?? null,
        ];
    }
}
