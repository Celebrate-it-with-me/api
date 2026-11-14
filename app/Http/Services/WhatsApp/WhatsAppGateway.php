<?php

namespace App\Http\Services\WhatsApp;

use App\Models\WhatsApp\Template;

/** A way of talking to WhatsApp. `fake` for development and tests, `meta` for the Cloud API. Failures throw WhatsAppException. */
interface WhatsAppGateway
{
    public function name(): string;

    /** True when it has everything it needs to work (the fake always does). */
    public function isConfigured(): bool;

    /**
     * Send an approved template. `$bodyParams` are the positional body parameters; `$buttonPayloads` the payload of each
     * quick-reply button, in order. Returns the WhatsApp message id.
     *
     * @param  list<string>  $bodyParams
     * @param  list<string>  $buttonPayloads
     */
    public function sendTemplate(string $to, string $metaName, string $locale, array $bodyParams, array $buttonPayloads = []): string;

    /** Free text, only allowed inside the 24 h window after the guest wrote. Returns the WhatsApp message id. */
    public function sendText(string $to, string $text): string;

    /** Submit (or resubmit) a template for approval. @return array{id: string|null, status: string} status is one of our statuses */
    public function submitTemplate(Template $template): array;

    /** All templates of the account. @return list<array{id: string|null, name: string, locale: string, status: string, reason: string|null}> */
    public function fetchTemplates(): array;

    /** Whether the account answers with the configured credentials. */
    public function checkConnection(): bool;
}
