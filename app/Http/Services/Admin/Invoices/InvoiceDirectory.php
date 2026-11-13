<?php

namespace App\Http\Services\Admin\Invoices;

use App\Models\CreditNote;
use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/** Read side of the admin invoices view (docs/plans/invoices.md). Money is in cents of the invoice currency. */
class InvoiceDirectory
{
    /** @param array<string, mixed> $f validated filters from InvoiceIndexRequest */
    public function list(array $f): array
    {
        $query = Invoice::query()->with(['event' => fn ($q) => $q->withTrashed()->select('id', 'event_name')])->withCount('creditNotes');

        if (filled($f['search'] ?? null)) {
            $like = '%' . strtolower(addcslashes($f['search'], '\\%_')) . '%';
            $query->where(function (Builder $q) use ($like) {
                $q->whereRaw("lower(invoices.number) like ? escape '\\'", [$like])
                    ->orWhereRaw("lower(invoices.recipient->>'name') like ? escape '\\'", [$like])
                    ->orWhereRaw("lower(invoices.recipient->>'email') like ? escape '\\'", [$like]);
            });
        }
        if (filled($f['status'] ?? null)) {
            $query->where('invoices.status', $f['status']);
        }
        if (filled($f['purchaseId'] ?? null)) {
            $query->where('invoices.purchase_id', $f['purchaseId']);
        }
        if (filled($f['from'] ?? null)) {
            $query->where('invoices.issued_at', '>=', Carbon::parse($f['from'])->startOfDay());
        }
        if (filled($f['to'] ?? null)) {
            $query->where('invoices.issued_at', '<=', Carbon::parse($f['to'])->endOfDay());
        }

        $page = $query->orderByDesc('invoices.issued_at')->orderByDesc('invoices.id')
            ->paginate((int) ($f['perPage'] ?? 15), ['invoices.*'], 'page', (int) ($f['page'] ?? 1));

        return [
            'data' => $page->getCollection()->map(fn (Invoice $i) => $this->row($i))->values(),
            'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'lastPage' => $page->lastPage()],
        ];
    }

    public function detail(Invoice $invoice): array
    {
        $invoice->load(['event' => fn ($q) => $q->withTrashed()->select('id', 'event_name'), 'creditNotes'])->loadCount('creditNotes');

        return $this->row($invoice) + [
            'issuer' => $invoice->issuer,
            'recipient' => $invoice->recipient,
            'lines' => $invoice->lines,
            'subtotalCents' => $invoice->subtotal_cents,
            'discountCents' => $invoice->discount_cents,
            'taxRate' => $invoice->tax_rate,
            'taxCents' => $invoice->tax_cents,
            'paymentMethod' => $invoice->payment_method,
            // Gateway identifiers are never shown whole.
            'paymentReference' => $invoice->payment_reference ? '…' . substr($invoice->payment_reference, -4) : null,
            'voidedAt' => $invoice->voided_at?->toIso8601String(),
            'voidReason' => $invoice->void_reason,
            'creditNotes' => $invoice->creditNotes->map(fn (CreditNote $n) => [
                'id' => $n->id, 'number' => $n->number, 'amountCents' => $n->amount_cents, 'taxCents' => $n->tax_cents,
                'currency' => $n->currency, 'reason' => $n->reason, 'issuedAt' => $n->issued_at->toIso8601String(),
            ])->values(),
        ];
    }

    private function row(Invoice $i): array
    {
        return [
            'id' => $i->id,
            'number' => $i->number,
            'status' => $i->status,
            'purchaseId' => $i->purchase_id,
            'event' => $i->event ? ['id' => $i->event->id, 'name' => $i->event->event_name] : null,
            'recipient' => ['name' => $i->recipient['name'] ?? null, 'email' => $i->recipient['email'] ?? null],
            'totalCents' => $i->total_cents,
            'currency' => $i->currency,
            'locale' => $i->locale,
            'issuedAt' => $i->issued_at->toIso8601String(),
            'emailedAt' => $i->emailed_at?->toIso8601String(),
            'creditNotesCount' => $i->credit_notes_count ?? 0,
        ];
    }
}
