<?php

namespace App\Http\Requests\app\EventComment;

use App\Enums\EventCommentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class OrganizerListEventCommentsRequest extends FormRequest
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
            'status' => ['nullable', new Enum(EventCommentStatus::class)],
            'pinned' => ['nullable', 'boolean'],
            'favorite' => ['nullable', 'boolean'],
        ];
    }
}
