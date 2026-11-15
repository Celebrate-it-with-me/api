<?php

namespace App\Http\Requests\Admin\Templates\Packages;

use Illuminate\Foundation\Http\FormRequest;

/** The zip of a template package. Errors are machine codes: `errors.package[0]` = required | not_a_zip | too_large. */
class UploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route middleware (admin.can:admin.templates.manage) decides
    }

    public function rules(): array
    {
        // 8 MB (spec 1.6): the zip is validated again byte by byte while it is read.
        return ['package' => ['required', 'file', 'mimes:zip', 'max:8192']];
    }

    public function messages(): array
    {
        return [
            'package.required' => 'required',
            'package.uploaded' => 'too_large',
            'package.max' => 'too_large',
            'package.*' => 'not_a_zip',
        ];
    }
}
