<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDressCodeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Permission check will be handled in controller or policy based on event access
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'dressCodeType' => [
                'required',
                Rule::in(['formal', 'semi-formal', 'casual', 'thematic', 'black-tie']),
            ],
            'description' => 'nullable|string|max:500',
            'reservedColors' => ['required', 'string', function ($attribute, $value, $fail) {
                $colors = json_decode($value, true);
                if (!is_array($colors)) {
                    $fail('The ' . $attribute . ' must be a valid JSON array of colors.');
                    return;
                }
                foreach ($colors as $color) {
                    if (!preg_match('/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $color)) {
                        $fail('The ' . $attribute . ' contains an invalid hex color: ' . $color);
                    }
                }
            }],
            'dressCodeImages' => 'nullable|array',
            'dressCodeImages.*' => 'image|max:2048',
        ];
    }
}
