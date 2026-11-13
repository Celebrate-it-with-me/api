<?php

namespace App\Http\Requests\Admin\Revenue;

use Illuminate\Foundation\Http\FormRequest;

/** Query string of GET admin/revenue/purchases. Authorization is done by the `admin.can` route middleware. */
class PurchaseIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:pending,paid,refunded,failed'],
            'gateway' => ['nullable', 'in:fake,stripe,manual'],
            'founder' => ['nullable', 'boolean'],
            'coupon' => ['nullable', 'string', 'max:64'],
            'paidFrom' => ['nullable', 'date_format:Y-m-d'],
            'paidTo' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:paidFrom'],
            'createdFrom' => ['nullable', 'date_format:Y-m-d'],
            'createdTo' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:createdFrom'],
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
