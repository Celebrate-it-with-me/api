<?php

namespace App\Http\Requests;

use App\Models\DressCode;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Saves the dress code of an event (it has at most one): `POST` creates it or replaces it.
 * Sent as multipart because of the uploads; lists go as `reservedColors[]=#aabbcc`.
 */
class SaveDressCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Access to the event is checked by the `event.access` middleware on the route.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $hex = ['required', 'string', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'];

        return [
            'dressCodeType' => ['required', Rule::in(DressCode::TYPES)],
            'description' => 'nullable|string|max:600',
            'reservedColors' => 'nullable|array|max:' . DressCode::MAX_COLORS,
            'reservedColors.*' => $hex,
            'suggestedColors' => 'nullable|array|max:' . DressCode::MAX_COLORS,
            'suggestedColors.*' => $hex,
            // The uploaded pictures to keep (by id); the rest are removed.
            'keepImageIds' => 'nullable|array',
            'keepImageIds.*' => 'integer',
            // The picks from the app's photo bank, as `type/name` keys: the full set wanted.
            'bankImages' => 'nullable|array',
            'bankImages.*' => ['string', 'max:80', 'regex:/^[a-z0-9-]+\/[a-z0-9-]+$/'],
            'dressCodeImages' => 'nullable|array',
            'dressCodeImages.*' => 'image|mimes:jpeg,png,webp|max:2048',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $total = count($this->input('keepImageIds', []))
                + count($this->input('bankImages', []))
                + count($this->file('dressCodeImages') ?? []);

            if ($total > DressCode::MAX_IMAGES) {
                $validator->errors()->add('dressCodeImages', 'A dress code can have at most ' . DressCode::MAX_IMAGES . ' pictures.');
            }
        }];
    }
}
