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
            'eventName' => 'required|string|max:255',
            'eventDescription' => 'nullable|string',
            'eventDate' => 'required|date_format:m/d/Y',
            'status' => 'required|in:draft,published,archived,canceled',
            'customUrlSlug' => 'nullable|string|unique:events,custom_url_slug|regex:/^[a-z0-9-]+$/',
            'visibility' => 'required|in:public,private,restricted',
        ];
    }
}
