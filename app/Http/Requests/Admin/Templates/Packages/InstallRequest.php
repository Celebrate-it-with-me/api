<?php

namespace App\Http\Requests\Admin\Templates\Packages;

use App\Http\Services\Admin\AdminAudit;
use Illuminate\Foundation\Http\FormRequest;

class InstallRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['stage_token' => ['required', 'string', 'size:26'], 'reason' => AdminAudit::reasonRule()];
    }

    public function messages(): array
    {
        return ['*.required' => 'required', '*' => 'invalid'];
    }
}
