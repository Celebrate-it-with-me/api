<?php

namespace App\Http\Requests\app;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGuestRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'gender' => ['nullable', 'string', 'in:male,female,other'],
            'rsvp_status' => ['sometimes', 'string', 'in:pending,attending,not-attending'],
            'assigned_menu_id' => ['nullable', 'exists:menus,id'],
            'notes' => ['nullable', 'string'],
            'is_vip' => ['sometimes', 'boolean'],
            'tags' => ['nullable', 'array'],
        ];
    }
}
