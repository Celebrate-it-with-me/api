<?php

namespace App\Console\Commands;

use App\Http\Services\Invoices\InvoiceService;
use App\Models\EventPurchase;
use App\Models\Invoice;
use Illuminate\Console\Command;

/** Safety net for payments confirmed before invoicing existed, or whose invoice or mail failed. Safe to repeat. */
class BackfillInvoices extends Command
{
    protected $signature = 'invoices:backfill {--send : Also mail the invoices that were never mailed}';

    protected $description = 'Issue the missing invoices of paid purchases (and optionally mail the unsent ones)';

    public function handle(InvoiceService $invoices): int
    {
        $issued = 0;
        EventPurchase::query()->where('status', 'paid')
            ->whereDoesntHave('invoices', fn ($q) => $q->where('status', 'issued'))
            ->orderBy('id')->each(function (EventPurchase $purchase) use ($invoices, &$issued) {
                [, $created] = $invoices->issueForPurchase($purchase);
                $issued += (int) $created;
            });

        $sent = 0;
        if ($this->option('send')) {
            Invoice::query()->where('status', 'issued')->whereNull('emailed_at')->orderBy('id')->each(function (Invoice $invoice) use ($invoices, &$sent) {
                $invoices->deliver($invoice);
                $sent++;
            });
        }

        $this->info("Issued {$issued} invoice(s), mailed {$sent}.");

        return self::SUCCESS;
    }
}
