<?php

namespace App\Http\Services\Invoices;

use App\Mail\CreditNoteMail;
use App\Mail\InvoiceMail;
use App\Models\BillingProfile;
use App\Models\CreditNote;
use App\Models\EventPurchase;
use App\Models\Invoice;
use App\Support\Mail\MailLocale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;

/**
 * Issues invoices and credit notes (docs/plans/invoices.md). Numbers are consecutive per series and year with no gaps:
 * the counter row is locked and incremented inside the same transaction that creates the document, so a failure
 * rolls the number back too.
 */
class InvoiceService
{
    /**
     * Issues the invoice of a paid purchase. Idempotent: a purchase has at most one live invoice.
     *
     * @return array{0: Invoice, 1: bool} the invoice and whether this call created it
     */
    public function issueForPurchase(EventPurchase $purchase): array
    {
        return DB::transaction(function () use ($purchase) {
            // Serialises concurrent attempts for the same purchase.
            $purchase = EventPurchase::query()->lockForUpdate()->with(['event' => fn ($q) => $q->withTrashed(), 'buyer.preferences'])->findOrFail($purchase->id);

            $existing = Invoice::query()->where('purchase_id', $purchase->id)->where('status', 'issued')->first();
            if ($existing) {
                return [$existing, false];
            }
            if ($purchase->status !== 'paid') {
                throw new InvalidArgumentException('Only a paid purchase can be invoiced.');
            }

            $now = now();
            $series = config('invoicing.series.invoice');
            $sequence = $this->nextSequence($series, $now->year);
            $money = $this->amounts($purchase);
            $locale = MailLocale::forUser($purchase->buyer);

            $invoice = Invoice::create([
                'number' => $this->format($series, $now->year, $sequence), 'series' => $series, 'year' => $now->year, 'sequence' => $sequence,
                'purchase_id' => $purchase->id, 'user_id' => $purchase->user_id, 'event_id' => $purchase->event_id, 'status' => 'issued',
                'issuer' => config('invoicing.issuer'),
                'recipient' => $this->recipient($purchase),
                'lines' => [[
                    'description_key' => 'full_event', 'event_name' => $purchase->event?->event_name,
                    'quantity' => 1, 'unit_cents' => $money['gross'], 'total_cents' => $money['gross'],
                ]],
                'subtotal_cents' => $money['gross'], 'discount_cents' => $money['discount'],
                'tax_rate' => $money['rate'], 'tax_cents' => $money['tax'], 'total_cents' => $money['total'],
                'currency' => $purchase->currency, 'payment_method' => $purchase->gateway,
                'payment_reference' => $purchase->gateway_payment_id ?? $purchase->gateway_session_id,
                'locale' => $locale, 'issued_at' => $now,
            ]);

            return [$invoice, true];
        });
    }

    /**
     * Cancels the live invoice of a purchase with a credit note for the full amount (a refund is always full).
     * Call it inside the refund transaction. Returns null when the purchase was never invoiced.
     */
    public function creditForRefund(EventPurchase $purchase, string $reason): ?CreditNote
    {
        $invoice = Invoice::query()->where('purchase_id', $purchase->id)->where('status', 'issued')->lockForUpdate()->first();
        if (! $invoice) {
            return null;
        }

        return DB::transaction(function () use ($invoice, $purchase, $reason) {
            $now = now();
            $series = config('invoicing.series.credit_note');
            $sequence = $this->nextSequence($series, $now->year);

            $note = CreditNote::create([
                'number' => $this->format($series, $now->year, $sequence), 'series' => $series, 'year' => $now->year, 'sequence' => $sequence,
                'invoice_id' => $invoice->id, 'purchase_id' => $purchase->id, 'reason' => mb_substr($reason, 0, 500),
                'amount_cents' => $invoice->total_cents, 'tax_cents' => $invoice->tax_cents, 'currency' => $invoice->currency,
                'locale' => $invoice->locale, 'issued_at' => $now,
            ]);
            $invoice->update(['status' => 'void', 'voided_at' => $now, 'void_reason' => mb_substr($reason, 0, 500)]);

            return $note;
        });
    }

    /** Stores the PDF and mails it to the buyer (or to `$email`). */
    public function deliver(Invoice $invoice, ?string $email = null): void
    {
        app(InvoicePdf::class)->contents($invoice);
        Mail::to($email ?? $invoice->recipient['email'])->send(new InvoiceMail($invoice));
        $invoice->update(['emailed_at' => now()]);
    }

    /**
     * Mails the credit note of a refund once. The `emailed_at` mark is claimed with one conditional UPDATE before
     * sending, so a retry or a concurrent call never sends it twice; if the mail fails the claim is released (so a
     * later retry can send it) and the error is logged. It never throws: a refund is already done by now.
     */
    public function deliverCreditNote(CreditNote $note): bool
    {
        $claimed = CreditNote::query()->whereKey($note->id)->whereNull('emailed_at')->update(['emailed_at' => now()]);
        if (! $claimed) {
            return false;
        }

        try {
            Mail::to($note->invoice->recipient['email'])->send(new CreditNoteMail($note->refresh()));

            return true;
        } catch (\Throwable $e) {
            CreditNote::query()->whereKey($note->id)->update(['emailed_at' => null]);
            Log::error('Credit note delivery failed', ['credit_note_id' => $note->id, 'error' => $e->getMessage()]);
            report($e);

            return false;
        }
    }

    /** Sends a stored document to an address chosen by its owner (resend). Not idempotent on purpose. */
    public function sendCopy(Invoice|CreditNote $doc, string $email): void
    {
        $mail = $doc instanceof CreditNote ? new CreditNoteMail($doc) : new InvoiceMail($doc);
        Mail::to($email)->send($mail);
    }

    private function nextSequence(string $series, int $year): int
    {
        DB::table('invoice_counters')->insertOrIgnore(['series' => $series, 'year' => $year, 'last_number' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $row = DB::table('invoice_counters')->where('series', $series)->where('year', $year)->lockForUpdate()->first();
        $next = $row->last_number + 1;
        DB::table('invoice_counters')->where('id', $row->id)->update(['last_number' => $next, 'updated_at' => now()]);

        return $next;
    }

    private function format(string $series, int $year, int $sequence): string
    {
        return sprintf('%s-%d-%06d', $series, $year, $sequence);
    }

    /** The charged amount is the total; tax (zero until the accountant says otherwise) is the part of it that is tax. */
    private function amounts(EventPurchase $purchase): array
    {
        $total = $purchase->amount_cents;
        $rate = max(0, (float) config('invoicing.tax.rate'));
        $tax = $rate > 0 ? (int) round($total * $rate / (100 + $rate)) : 0;
        $gross = max($purchase->list_price_cents, $total);

        return ['gross' => $gross, 'discount' => $gross - $total, 'rate' => $rate, 'tax' => $tax, 'total' => $total];
    }

    private function recipient(EventPurchase $purchase): array
    {
        $profile = BillingProfile::query()->where('user_id', $purchase->user_id)->first();

        return [
            'name' => $profile?->name ?? $purchase->buyer?->name,
            // The invoice goes to the billing email when she gave one, otherwise to the account email.
            'email' => $profile?->billing_email ?: $purchase->buyer?->email,
            'address' => $profile?->address, 'city' => $profile?->city, 'state' => $profile?->state,
            'postal_code' => $profile?->postal_code, 'country' => $profile?->country, 'tax_id' => $profile?->tax_id,
        ];
    }
}
