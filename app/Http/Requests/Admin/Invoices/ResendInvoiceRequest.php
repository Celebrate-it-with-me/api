<?php

namespace App\Http\Requests\Admin\Invoices;

use App\Http\Services\Admin\AdminAudit;
use Illuminate\Foundation\Http\FormRequest;

class ResendInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['reason' => AdminAudit::reasonRule(), 'email' => ['nullable', 'email:rfc', 'max:255']];
    }
}
