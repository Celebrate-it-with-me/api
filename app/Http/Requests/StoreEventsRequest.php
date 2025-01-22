<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEventsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'event_name' => 'required|string|max:255',
            'event_description' => 'nullable|string',
            'event_date' => 'required|date',
            'organizer_id' => 'required|exists:users,id',
            'status' => 'required|in:draft,published,archived,canceled',
            'custom_url_slug' => 'nullable|string|unique:events,custom_url_slug|regex:/^[a-z0-9-]+$/',
            'visibility' => 'required|in:public,private,restricted',
        ];
    }
}
