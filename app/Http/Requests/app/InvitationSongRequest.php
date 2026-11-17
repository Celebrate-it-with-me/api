<?php

namespace App\Http\Requests\app;

use Illuminate\Foundation\Http\FormRequest;

/** A song to suggest. The data comes from the client, so it is bounded and its addresses limited to Spotify's hosts. */
class InvitationSongRequest extends FormRequest
{
    /** Hosts (and their subdomains) that may serve a cover or a preview. */
    private const TRUSTED_HOSTS = ['scdn.co', 'spotifycdn.com', 'spotify.com'];

    public function authorize(): bool
    {
        // The guest code in the URL is the credential; it is checked in the controller.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $text = ['bail', 'string', 'max:255', 'regex:' . InvitationRsvpRequest::NAME_PATTERN];
        $url = ['bail', 'nullable', 'string', 'max:500', fn ($attribute, $value, $fail) => self::isTrustedUrl((string) $value) ?: $fail('invalid')];

        return [
            'platformId' => ['bail', 'required', 'string', 'regex:/^[A-Za-z0-9]{22}$/'],
            'title' => ['required', ...$text],
            'artist' => ['required', ...$text],
            'album' => ['nullable', ...$text],
            'thumbnailUrl' => $url,
            'previewUrl' => $url,
        ];
    }

    /** Codes, not sentences: the invitation writes the text in the guest's language. @return array<string, string> */
    public function messages(): array
    {
        $messages = [];
        foreach (['platformId', 'title', 'artist'] as $field) {
            $messages["{$field}.required"] = 'required';
        }
        foreach (['platformId', 'title', 'artist', 'album', 'thumbnailUrl', 'previewUrl'] as $field) {
            $messages["{$field}.string"] = 'invalid';
            $messages["{$field}.regex"] = 'invalid';
            $messages["{$field}.max"] = 'too_long';
        }

        return $messages;
    }

    protected function prepareForValidation(): void
    {
        $clean = [];
        foreach (['title', 'artist', 'album', 'thumbnailUrl', 'previewUrl', 'platformId'] as $field) {
            $value = $this->input($field);
            if (is_string($value)) {
                $value = trim($value);
                $clean[$field] = $value === '' && in_array($field, ['album', 'thumbnailUrl', 'previewUrl'], true) ? null : $value;
            }
        }

        $this->merge($clean);
    }

    /** https, a plain host (no port or credentials) of Spotify or its CDN. */
    public static function isTrustedUrl(string $url): bool
    {
        if (! preg_match('/^https:\/\/([A-Za-z0-9.-]+)(\/[^\s\x00-\x1F\x7F]*)?$/', $url, $match)) {
            return false;
        }
        $host = strtolower($match[1]);

        foreach (self::TRUSTED_HOSTS as $trusted) {
            if ($host === $trusted || str_ends_with($host, '.' . $trusted)) {
                return true;
            }
        }

        return false;
    }
}
