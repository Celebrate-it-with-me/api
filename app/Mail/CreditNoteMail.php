<?php

namespace App\Mail;

use App\Http\Services\Invoices\InvoicePdf;
use App\Models\CreditNote;
use App\Support\Mail\MailLinks;
use App\Support\Mail\MailLocale;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** The credit note of a refund, with the PDF attached (built on the fly, so it also works for previews). */
class CreditNoteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public CreditNote $note)
    {
        $this->locale($note->locale);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('mail.credit_note.subject', ['number' => $this->note->number]));
    }

    public function content(): Content
    {
        $note = $this->note;
        $invoice = $note->invoice;
        $amount = number_format($note->amount_cents / 100, 2);

        return new Content(markdown: 'mail.credit-note', with: [
            'name' => MailLocale::firstName($invoice->recipient['name'] ?? ''),
            'number' => $note->number, 'invoiceNumber' => $invoice->number,
            'amount' => $note->currency === 'USD' ? '$' . $amount : $amount . ' ' . $note->currency,
            'event' => $invoice->lines[0]['event_name'] ?? '', 'purchasesUrl' => MailLinks::app('settings/purchases'),
        ]);
    }

    public function attachments(): array
    {
        $note = $this->note;

        return [Attachment::fromData(fn () => app(InvoicePdf::class)->render($note), $note->number . '.pdf')->withMime('application/pdf')];
    }
}
