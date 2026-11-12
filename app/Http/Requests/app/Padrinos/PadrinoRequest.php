<?php

namespace App\Http\Requests\app\Padrinos;

use App\Models\Guest;
use App\Models\Padrino;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A padrino: create and update take the same fields. */
class PadrinoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9][0-9 ().-]{5,29}$/'],
            'relationship' => ['nullable', 'string', 'max:80'],
            'status' => ['required', Rule::in(Padrino::STATUSES)],
            'guestId' => ['nullable', 'integer', Rule::exists(Guest::class, 'id')->where('event_id', $this->route('event')->id)->whereNull('parent_id')],
            'publicThanks' => ['required', 'boolean'],
            'thanksText' => ['nullable', 'string', 'max:300'],
        ];
    }

    /** Column names for the model. @return array<string, mixed> */
    public function padrino(): array
    {
        $d = $this->validated();
        $blank = fn (string $key) => ($d[$key] ?? null) ? trim($d[$key]) : null;

        return [
            'name' => trim($d['name']),
            'phone' => $blank('phone'),
            'relationship' => $blank('relationship'),
            'status' => $d['status'],
            'guest_id' => $d['guestId'] ?? null,
            'public_thanks' => (bool) $d['publicThanks'],
            'thanks_text' => $blank('thanksText'),
        ];
    }
}
