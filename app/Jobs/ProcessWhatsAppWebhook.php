<?php

namespace App\Jobs;

use App\Http\Services\WhatsApp\WebhookProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Processes a verified webhook body off the request, so Meta gets its 200 at once. Idempotent per event. */
class ProcessWhatsAppWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    /** @param array<string, mixed> $payload */
    public function __construct(public array $payload)
    {
        $this->onQueue('whatsapp');
    }

    public function handle(WebhookProcessor $processor): void
    {
        $processor->process($this->payload);
    }
}
