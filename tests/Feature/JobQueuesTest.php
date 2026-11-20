<?php

use App\Jobs\DeliverCreditNoteEmail;
use App\Jobs\DeliverInvoiceEmail;
use App\Jobs\ProcessWhatsAppWebhook;
use App\Jobs\SendPasswordResetLink;
use App\Jobs\SendWhatsAppMessage;

// A worker only listens to the queues it is told about: `queue:work --queue=mail,whatsapp,default` (docker-compose.yml, Laravel Cloud).
// A job on a queue nobody names never runs, so every critical job states its queue and its retry policy.
it('puts every critical job on a named queue the worker listens to', function (object $job, string $queue) {
    expect($job->queue)->toBe($queue);
})->with([
    'invoice mail' => [fn () => new DeliverInvoiceEmail(1), 'mail'],
    'credit note mail' => [fn () => new DeliverCreditNoteEmail(1), 'mail'],
    'password reset' => [fn () => new SendPasswordResetLink('a@b.test'), 'mail'],
    'whatsapp send' => [fn () => new SendWhatsAppMessage(1), 'whatsapp'],
    'whatsapp webhook' => [fn () => new ProcessWhatsAppWebhook([]), 'whatsapp'],
]);

it('retries the critical jobs with a backoff', function () {
    $invoice = new DeliverInvoiceEmail(1);
    $reset = new SendPasswordResetLink('a@b.test');

    expect($invoice->tries)->toBeGreaterThan(1)->and($invoice->backoff())->not->toBeEmpty()
        ->and($reset->tries)->toBeGreaterThan(1)->and($reset->backoff())->not->toBeEmpty()
        ->and((new SendWhatsAppMessage(1))->backoff)->not->toBeEmpty()
        ->and((new ProcessWhatsAppWebhook([]))->backoff)->not->toBeEmpty();
});

it('documents the worker with every named queue in docker-compose', function () {
    expect(file_get_contents(base_path('docker-compose.yml')))->toContain('queue:work --queue=mail,whatsapp,default');
});
