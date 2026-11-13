<?php

namespace App\Http\Requests\Admin\Events;

use Illuminate\Foundation\Http\FormRequest;

/** Query string of GET admin/events. Authorization is done by the `admin.can` route middleware. */
class EventIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:draft,published,archived'],
            'stage' => ['nullable', 'in:save_the_date,invitation'],
            'plan' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'paymentPending' => ['nullable', 'boolean'],
            'internal' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'in:createdAt,startDate'],
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
