<?php

namespace App\Jobs;

use App\Http\Services\Invoices\InvoiceService;
use App\Models\Invoice;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Renders the PDF of an invoice and mails it. Out of the request because the PDF takes 0.1-0.3 s and the mail provider
 * is a network call. Idempotent: the first delivery is skipped when `emailed_at` is already set (a retry after a
 * success, or the same job twice); a resend asked by staff ($resend) always goes out.
 */
class DeliverInvoiceEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $invoiceId, public readonly ?string $email = null, public readonly bool $resend = false)
    {
        $this->afterCommit();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(InvoiceService $invoices): void
    {
        $invoice = Invoice::query()->find($this->invoiceId);

        if ($invoice === null || (! $this->resend && $invoice->emailed_at !== null)) {
            return;
        }

        $invoices->deliver($invoice, $this->email);
    }
}
