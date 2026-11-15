<?php

namespace App\Http\Requests\Admin\Templates\Packages;

use App\Http\Services\Admin\AdminAudit;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Activating a package needs the quality checklist of the review, all six items confirmed (the server audits who and
 * when): a missing or false item is `errors.checklist[0] = incomplete`.
 */
class ActivateRequest extends FormRequest
{
    public const CHECKLIST = ['accessibility', 'contrast', 'long_names', 'no_photo', 'sections_reordered', 'performance'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => AdminAudit::reasonRule(),
            'ack_third_party_marks' => ['sometimes', 'boolean'],
            'checklist' => ['required', 'array'],
        ];
    }

    public function messages(): array
    {
        return ['checklist.required' => 'incomplete', 'checklist.array' => 'incomplete', '*.required' => 'required', '*' => 'invalid'];
    }

    public function after(): array
    {
        return [function ($validator) {
            $list = $this->input('checklist');
            $complete = is_array($list) && collect(self::CHECKLIST)->every(fn (string $key) => ($list[$key] ?? null) === true);
            if (! $complete && ! $validator->errors()->has('checklist')) {
                $validator->errors()->add('checklist', 'incomplete');
            }
        }];
    }
}
