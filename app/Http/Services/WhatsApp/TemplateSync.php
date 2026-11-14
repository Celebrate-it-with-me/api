<?php

namespace App\Http\Services\WhatsApp;

use App\Models\WhatsApp\Template;

/** Pulls the review status of every template from the gateway (the webhook also pushes changes; this is the safety net). */
class TemplateSync
{
    public function __construct(private readonly WhatsAppService $whatsapp) {}

    /** @throws WhatsAppException */
    public function run(): void
    {
        foreach ($this->whatsapp->gateway()->fetchTemplates() as $remote) {
            $template = Template::query()->where('meta_name', $remote['name'])->where('locale', $remote['locale'])->first();
            if (! $template) {
                continue;
            }
            // A draft we never submitted must not be flipped by a template that merely shares the name.
            $template->forceFill([
                'meta_template_id' => $remote['id'] ?? $template->meta_template_id,
                'status' => $remote['status'],
                'rejection_reason' => $remote['status'] === 'rejected' ? $remote['reason'] : null,
                'approved_at' => $remote['status'] === 'approved' ? ($template->approved_at ?? now()) : $template->approved_at,
            ])->save();
        }
    }
}
