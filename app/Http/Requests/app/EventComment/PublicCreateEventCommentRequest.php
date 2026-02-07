<?php

namespace App\Http\Requests\app\EventComment;

use Illuminate\Foundation\Http\FormRequest;

class PublicCreateEventCommentRequest extends FormRequest
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
            'guestCode' => ['required', 'string', 'max:255'],
            'comment' => ['required', 'string', 'min:1', 'max:2000']
        ];
    }
}
