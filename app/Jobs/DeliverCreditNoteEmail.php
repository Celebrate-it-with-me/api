<?php

namespace App\Jobs;

use App\Http\Services\Invoices\InvoiceService;
use App\Models\CreditNote;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Mails the credit note of a refund, off the request. InvoiceService::deliverCreditNote already claims `emailed_at`
 * with one conditional UPDATE, releases it if the mail fails and never throws, so running this twice sends once.
 */
class DeliverCreditNoteEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $creditNoteId)
    {
        $this->onQueue('mail');
        $this->afterCommit();
    }

    public function handle(InvoiceService $invoices): void
    {
        $note = CreditNote::query()->find($this->creditNoteId);

        if ($note !== null) {
            $invoices->deliverCreditNote($note);
        }
    }
}
