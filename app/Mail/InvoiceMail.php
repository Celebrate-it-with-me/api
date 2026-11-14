<?php

namespace App\Mail;

use App\Http\Services\Invoices\InvoicePdf;
use App\Models\Invoice;
use App\Support\Mail\MailLinks;
use App\Support\Mail\MailLocale;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** The invoice of a payment, with the PDF attached (built on the fly, so it also works for previews). */
class InvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Invoice $invoice)
    {
        $this->locale($invoice->locale);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('mail.invoice.subject', ['number' => $this->invoice->number]));
    }

    public function content(): Content
    {
        $invoice = $this->invoice;
        $total = '$' . number_format($invoice->total_cents / 100, 2);

        return new Content(markdown: 'mail.invoice', with: [
            'name' => MailLocale::firstName($invoice->recipient['name'] ?? ''),
            'number' => $invoice->number, 'total' => $invoice->currency === 'USD' ? $total : number_format($invoice->total_cents / 100, 2) . ' ' . $invoice->currency,
            'event' => $invoice->lines[0]['event_name'] ?? '', 'purchasesUrl' => MailLinks::app('settings/purchases'),
        ]);
    }

    public function attachments(): array
    {
        $invoice = $this->invoice;

        return [Attachment::fromData(fn () => app(InvoicePdf::class)->render($invoice), $invoice->number . '.pdf')->withMime('application/pdf')];
    }
}
