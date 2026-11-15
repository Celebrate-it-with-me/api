<?php

namespace App\Http\Requests\app;

use App\Http\Services\AppServices\BackgroundMusicServices;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * Upload (or replace) the background song. Errors are codes, not sentences: the app writes the text.
 * The type is checked by the content of the file, never by what the client says it is; the extension of the
 * name must agree (mp3, m4a, aac, ogg). WAV is not accepted.
 */
class BackgroundMusicRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function prepareForValidation(): void
    {
        $this->merge(['autoplay' => filter_var($this->input('autoplay'), FILTER_VALIDATE_BOOLEAN)]);
    }

    /** @return array<string, ValidationRule|array|string> */
    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:60'],
            'iconSize' => ['nullable', 'string', 'max:50'],
            'iconPosition' => ['nullable', 'string', 'max:50'],
            'iconColor' => ['nullable', 'string', 'max:50'],
            'songFile' => [
                'bail', 'required', 'file',
                'mimetypes:' . implode(',', BackgroundMusicServices::ALLOWED_MIMETYPES),
                'max:10240',
                function (string $attribute, mixed $value, Closure $fail) {
                    $extension = $value instanceof UploadedFile ? strtolower($value->getClientOriginalExtension()) : '';

                    if (! in_array($extension, BackgroundMusicServices::ALLOWED_EXTENSIONS, true)) {
                        $fail('invalid_type');
                    }
                },
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'songFile.required' => 'required',
            'songFile.file' => 'invalid_type',
            'songFile.uploaded' => 'too_large',
            'songFile.mimetypes' => 'invalid_type',
            'songFile.max' => 'too_large',
            'title.string' => 'invalid_type',
            'title.max' => 'too_long',
        ];
    }
}
