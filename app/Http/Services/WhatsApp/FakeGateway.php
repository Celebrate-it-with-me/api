<?php

namespace App\Http\Services\WhatsApp;

use App\Models\WhatsApp\Template;
use Illuminate\Support\Str;

/** Sends nothing: records the calls and approves templates at once. Failures can be queued with failNext() for tests. */
class FakeGateway implements WhatsAppGateway
{
    /** @var list<array<string, mixed>> */
    public static array $sent = [];

    /** @var list<WhatsAppException> */
    public static array $failures = [];

    public static function reset(): void
    {
        self::$sent = [];
        self::$failures = [];
    }

    public static function failNext(WhatsAppException $e): void
    {
        self::$failures[] = $e;
    }

    public function name(): string
    {
        return 'fake';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function sendTemplate(string $to, string $metaName, string $locale, array $bodyParams, array $buttonPayloads = []): string
    {
        $this->maybeFail();
        self::$sent[] = ['type' => 'template', 'to' => $to, 'template' => $metaName, 'locale' => $locale, 'params' => $bodyParams, 'payloads' => $buttonPayloads];

        return $this->id();
    }

    public function sendText(string $to, string $text): string
    {
        $this->maybeFail();
        self::$sent[] = ['type' => 'text', 'to' => $to, 'text' => $text];

        return $this->id();
    }

    public function submitTemplate(Template $template): array
    {
        return ['id' => $template->meta_template_id ?: 'fake_tpl_' . Str::random(10), 'status' => 'approved'];
    }

    public function fetchTemplates(): array
    {
        return Template::query()->get()->map(fn (Template $t) => [
            'id' => $t->meta_template_id, 'name' => $t->meta_name, 'locale' => $t->locale, 'status' => $t->status, 'reason' => $t->rejection_reason,
        ])->all();
    }

    public function checkConnection(): bool
    {
        return true;
    }

    private function id(): string
    {
        return 'wamid.fake.' . Str::uuid()->toString();
    }

    private function maybeFail(): void
    {
        if (self::$failures !== []) {
            throw array_shift(self::$failures);
        }
    }
}
