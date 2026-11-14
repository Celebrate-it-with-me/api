<?php

namespace App\Http\Services\WhatsApp;

use App\Models\WhatsApp\Template;
use App\Support\WhatsApp\TemplateCatalog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp Business Cloud API through the Graph API (graph.facebook.com, version from WHATSAPP_GRAPH_VERSION,
 * v26.0 when this was written):
 *   POST /{phone-number-id}/messages              send a template or a session text
 *   POST /{waba-id}/message_templates             create a template   |  POST /{template-id} to edit one
 *   GET  /{waba-id}/message_templates             list templates and their review status
 *   GET  /{phone-number-id}                       connection check
 * Quick-reply buttons carry our `rsvp:{guestId}:yes|no` payload as a `button` component of type `payload`.
 */
class MetaGateway implements WhatsAppGateway
{
    private const LANGUAGES = ['es' => 'es', 'en' => 'en_US'];

    /** Meta error codes that are worth retrying later: throttling and temporary outages. */
    private const RETRYABLE_CODES = [1, 2, 4, 17, 32, 613, 80007, 130429, 131048, 131056, 133016];

    public function name(): string
    {
        return 'meta';
    }

    public function isConfigured(): bool
    {
        foreach (['phone_number_id', 'business_account_id', 'access_token', 'app_secret', 'verify_token'] as $key) {
            if (! config("services.whatsapp.$key")) {
                return false;
            }
        }

        return true;
    }

    public function sendTemplate(string $to, string $metaName, string $locale, array $bodyParams, array $buttonPayloads = []): string
    {
        $components = [];
        if ($bodyParams !== []) {
            $components[] = ['type' => 'body', 'parameters' => array_map(fn (string $p) => ['type' => 'text', 'text' => $p], array_values($bodyParams))];
        }
        foreach (array_values($buttonPayloads) as $i => $payload) {
            $components[] = ['type' => 'button', 'sub_type' => 'quick_reply', 'index' => (string) $i, 'parameters' => [['type' => 'payload', 'payload' => $payload]]];
        }

        $template = ['name' => $metaName, 'language' => ['code' => self::LANGUAGES[$locale] ?? $locale]];
        if ($components !== []) {
            $template['components'] = $components;
        }

        return $this->messageId($this->post($this->phoneId() . '/messages', [
            'messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => ltrim($to, '+'), 'type' => 'template', 'template' => $template,
        ]));
    }

    public function sendText(string $to, string $text): string
    {
        return $this->messageId($this->post($this->phoneId() . '/messages', [
            'messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => ltrim($to, '+'), 'type' => 'text',
            'text' => ['preview_url' => false, 'body' => $text],
        ]));
    }

    public function submitTemplate(Template $template): array
    {
        $components = [[
            'type' => 'BODY',
            'text' => $template->body,
            'example' => ['body_text' => [array_map(fn (string $v) => TemplateCatalog::SAMPLES[$v] ?? 'ejemplo', $template->variables)]],
        ]];
        if ($template->buttons) {
            $components[] = ['type' => 'BUTTONS', 'buttons' => array_map(fn (array $b) => ['type' => 'QUICK_REPLY', 'text' => $b['text']], $template->buttons)];
        }

        if ($template->meta_template_id) {
            // Already known to Meta (rejected or approved before): edit it, which sends it to review again.
            $json = $this->post($template->meta_template_id, ['category' => strtoupper($template->category), 'components' => $components]);

            return ['id' => $template->meta_template_id, 'status' => ($json['success'] ?? false) ? 'submitted' : 'draft'];
        }

        $json = $this->post($this->wabaId() . '/message_templates', [
            'name' => $template->meta_name, 'language' => self::LANGUAGES[$template->locale] ?? $template->locale,
            'category' => strtoupper($template->category), 'components' => $components,
        ]);

        return ['id' => isset($json['id']) ? (string) $json['id'] : null, 'status' => $this->mapStatus($json['status'] ?? 'PENDING')];
    }

    public function fetchTemplates(): array
    {
        $out = [];
        $url = $this->wabaId() . '/message_templates';
        $query = ['fields' => 'id,name,language,status,category,rejected_reason', 'limit' => 100];

        for ($page = 0; $page < 20 && $url; $page++) {
            $json = $this->request('get', $url, $query);
            foreach ($json['data'] ?? [] as $row) {
                $out[] = [
                    'id' => isset($row['id']) ? (string) $row['id'] : null, 'name' => (string) ($row['name'] ?? ''),
                    'locale' => str_starts_with((string) ($row['language'] ?? ''), 'en') ? 'en' : 'es',
                    'status' => $this->mapStatus($row['status'] ?? ''), 'reason' => $this->reason($row['rejected_reason'] ?? null),
                ];
            }
            $url = $json['paging']['next'] ?? null;
            $query = [];
        }

        return $out;
    }

    public function checkConnection(): bool
    {
        try {
            $this->request('get', $this->phoneId(), ['fields' => 'display_phone_number,verified_name']);

            return true;
        } catch (WhatsAppException) {
            return false;
        }
    }

    public function mapStatus(string $status): string
    {
        return match (strtoupper($status)) {
            'APPROVED' => 'approved',
            'REJECTED' => 'rejected',
            'PAUSED', 'DISABLED' => 'paused',
            default => 'submitted',
        };
    }

    /** Meta uses NONE when there is no reason. */
    private function reason(?string $reason): ?string
    {
        return $reason && strtoupper($reason) !== 'NONE' ? $reason : null;
    }

    /** @return array<string, mixed> */
    private function post(string $path, array $body): array
    {
        return $this->request('post', $path, $body);
    }

    /** @return array<string, mixed> */
    private function request(string $method, string $path, array $data): array
    {
        $http = $this->client();
        if (str_starts_with($path, 'http')) {
            // A paging URL is followed only on Graph itself: the access token must never go anywhere else (audit W-05).
            $parts = parse_url($path);
            $own = parse_url($this->base());
            if (($parts['scheme'] ?? '') !== 'https' || ! hash_equals((string) $own['host'], strtolower((string) ($parts['host'] ?? ''))) || isset($parts['user']) || (isset($parts['port']) && $parts['port'] !== 443)) {
                Log::warning('whatsapp: refused to follow a non-Graph URL');
                throw new WhatsAppException('invalid_paging_url', 'WhatsApp returned an unexpected URL.', false);
            }
            $url = $path;
        } else {
            $url = $this->base() . '/' . ltrim($path, '/');
        }

        try {
            // A `query` option would replace the query string of a paging URL, so only pass it when there is something to add.
            $response = $method === 'get' ? $http->get($url, $data ?: null) : $http->post($url, $data);
        } catch (ConnectionException $e) {
            // A timeout may come after Meta accepted the request: a send must not be repeated blindly (audit W-06).
            throw new WhatsAppException('connection', 'Could not reach WhatsApp.', true, $method === 'post');
        }

        if ($response->failed()) {
            throw $this->exception($response);
        }

        return $response->json() ?? [];
    }

    private function exception(Response $response): WhatsAppException
    {
        $error = $response->json('error') ?? [];
        $code = (int) ($error['code'] ?? 0);
        $message = (string) ($error['error_data']['details'] ?? $error['message'] ?? 'WhatsApp error');
        $retryable = $response->status() === 429 || $response->serverError() || in_array($code, self::RETRYABLE_CODES, true);

        // A 5xx with no Meta error body says nothing about whether the message was taken.
        $ambiguous = $response->serverError() && ! in_array($code, self::RETRYABLE_CODES, true);

        return new WhatsAppException((string) ($code ?: $response->status()), mb_substr($message, 0, 500), $retryable, $ambiguous);
    }

    private function messageId(array $json): string
    {
        $id = $json['messages'][0]['id'] ?? null;
        if (! $id) {
            throw new WhatsAppException('no_message_id', 'WhatsApp did not return a message id.', false);
        }

        return (string) $id;
    }

    private function client(): PendingRequest
    {
        return Http::withToken((string) config('services.whatsapp.access_token'))->acceptJson()->asJson()->timeout(15)->withoutRedirecting();
    }

    private function base(): string
    {
        return 'https://graph.facebook.com/' . config('services.whatsapp.graph_version', 'v26.0');
    }

    private function phoneId(): string
    {
        return (string) config('services.whatsapp.phone_number_id');
    }

    private function wabaId(): string
    {
        return (string) config('services.whatsapp.business_account_id');
    }
}
