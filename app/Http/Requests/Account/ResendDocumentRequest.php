<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;

/** Resend to herself only: no free-form address, just which of her own two addresses. */
class ResendDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['to' => ['required', 'in:billing,account']];
    }
}
