<?php

namespace App\Http\Requests\Admin\Templates;

use App\Http\Services\Admin\AdminAudit;
use Illuminate\Foundation\Http\FormRequest;

class ReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route middleware (admin.can:admin.templates.manage) decides
    }

    public function rules(): array
    {
        return ['reason' => AdminAudit::reasonRule()];
    }
}
