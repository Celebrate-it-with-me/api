<?php

namespace App\Http\Controllers\AppControllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Services\Invoices\InvoicePdf;
use App\Models\CreditNote;
use App\Models\EventPurchase;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/** "Mis compras": the logged-in organizer's own paid purchases and their invoices. Nothing of anyone else's. */
class MyPurchasesController extends Controller
{
    public function __construct(private readonly InvoicePdf $pdf) {}

    public function index(Request $request): JsonResponse
    {
        $purchases = EventPurchase::query()
            ->where('user_id', $request->user()->id)->whereIn('status', ['paid', 'refunded'])
            ->with(['event' => fn ($q) => $q->withTrashed()->select('id', 'event_name'), 'invoices.creditNotes'])
            ->orderByDesc('paid_at')->orderByDesc('id')->limit(100)->get();

        return response()->json(['data' => $purchases->map(fn (EventPurchase $p) => [
            'id' => $p->id,
            'event' => $p->event ? ['id' => $p->event->id, 'name' => $p->event->event_name] : null,
            'status' => $p->status,
            'amountCents' => $p->amount_cents,
            'currency' => $p->currency,
            'paidAt' => $p->paid_at?->toIso8601String(),
            'refundedAt' => $p->refunded_at?->toIso8601String(),
            'invoices' => $p->invoices->map(fn (Invoice $i) => [
                'id' => $i->id, 'number' => $i->number, 'status' => $i->status, 'totalCents' => $i->total_cents,
                'issuedAt' => $i->issued_at->toIso8601String(),
                'creditNotes' => $i->creditNotes->map(fn (CreditNote $n) => [
                    'id' => $n->id, 'number' => $n->number, 'amountCents' => $n->amount_cents, 'issuedAt' => $n->issued_at->toIso8601String(),
                ])->values(),
            ])->values(),
        ])->values()]);
    }

    public function invoicePdf(Invoice $invoice): Response
    {
        Gate::authorize('view', $invoice);

        return $this->download($invoice);
    }

    public function creditNotePdf(CreditNote $creditNote): Response
    {
        Gate::authorize('viewCreditNote', [$creditNote->invoice]);

        return $this->download($creditNote);
    }

    private function download(Invoice|CreditNote $doc): Response
    {
        return response($this->pdf->contents($doc), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $this->pdf->filename($doc) . '"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
