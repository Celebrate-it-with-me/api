<?php

namespace App\Http\Requests;

use App\Models\EventLocation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create and update share the same rules: a place is always sent whole. */
class StoreEventLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(EventLocation::TYPES)],
            'name' => ['required', 'string', 'max:120'],
            'address' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'zipCode' => ['nullable', 'string', 'max:12'],
            'country' => ['required', 'string', 'max:120'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'placeId' => ['nullable', 'string', 'max:255'],
            'directions' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** Column names for the model. */
    public function place(): array
    {
        $data = $this->validated();

        return [
            'type' => $data['type'],
            'name' => $data['name'],
            'address' => $data['address'],
            'city' => $data['city'],
            'state' => $data['state'] ?? null,
            'zip_code' => $data['zipCode'] ?? null,
            'country' => $data['country'],
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'place_id' => $data['placeId'] ?? null,
            'notes' => ($data['directions'] ?? null) ?: null,
        ];
    }
}
