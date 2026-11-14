<?php

namespace App\Http\Services\Invoices;

use App\Models\CreditNote;
use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;

/**
 * PDF of an invoice or a credit note, in the document's language (dompdf, plain PHP: no browser needed). The documents
 * are immutable, so the file is stored once on the private disk and rebuilt only if it is missing.
 */
class InvoicePdf
{
    /** Bytes of the PDF, built on the fly (used by the mail attachment and the previews: no disk, no database). */
    public function render(Invoice|CreditNote $doc): string
    {
        $invoice = $doc instanceof CreditNote ? $doc->invoice : $doc;

        $previous = App::getLocale();
        App::setLocale($doc->locale);
        try {
            return Pdf::loadView('invoices.pdf', [
                'doc' => $doc, 'invoice' => $invoice, 'isCredit' => $doc instanceof CreditNote,
                'money' => fn (int $cents) => $this->money($cents, $doc->currency),
                'date' => fn ($d) => $d?->locale($doc->locale)->translatedFormat($doc->locale === 'es' ? 'j \d\e F \d\e Y' : 'F j, Y'),
            ])->setPaper('letter')->output();
        } finally {
            App::setLocale($previous);
        }
    }

    /** Stored bytes, creating the file the first time. */
    public function contents(Invoice|CreditNote $doc): string
    {
        $disk = Storage::disk(config('invoicing.disk'));
        if (! $doc->pdf_path || ! $disk->exists($doc->pdf_path)) {
            $path = config('invoicing.path') . "/{$doc->year}/{$doc->number}.pdf";
            $disk->put($path, $this->render($doc));
            $doc->forceFill(['pdf_path' => $path])->save();
        }

        return $disk->get($doc->pdf_path);
    }

    public function filename(Invoice|CreditNote $doc): string
    {
        return $doc->number . '.pdf';
    }

    private function money(int $cents, string $currency): string
    {
        $amount = number_format($cents / 100, 2);

        return $currency === 'USD' ? '$' . $amount : $amount . ' ' . $currency;
    }
}
