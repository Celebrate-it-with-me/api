<?php

namespace App\Http\Requests\WhatsApp;

use App\Http\Services\Admin\AdminAudit;
use App\Support\WhatsApp\TemplateCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** One request for the admin's writes and filters; the route name decides the rules. Messages are the contract's codes. */
class AdminWhatsAppRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // admin.can / admin.reauth on the routes
    }

    public function rules(): array
    {
        $reason = AdminAudit::reasonRule();

        return match ($this->route()->getActionMethod()) {
            'updateTemplate' => ['body' => ['sometimes', 'string', 'max:1024'], 'category' => ['sometimes', Rule::in(['utility', 'marketing'])], 'reason' => $reason],
            'submitTemplate', 'syncTemplates' => ['reason' => $reason],
            'test' => [
                'to' => ['required', 'string'], 'template_key' => ['required', Rule::in(TemplateCatalog::KEYS)],
                'locale' => ['required', Rule::in(TemplateCatalog::LOCALES)], 'reason' => $reason,
            ],
            'summary' => ['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']],
            'messages' => [
                'status' => ['nullable', 'string', Rule::in(['queued', 'sent', 'delivered', 'read', 'failed'])], 'event_id' => ['nullable', 'integer'],
                'template_key' => ['nullable', Rule::in(TemplateCatalog::KEYS)], 'q' => ['nullable', 'string', 'max:100'],
                'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            ],
            default => [],
        };
    }
}
