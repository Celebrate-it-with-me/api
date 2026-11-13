<?php

namespace App\Http\Controllers\Admin\Invoices;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Invoices\InvoiceIndexRequest;
use App\Http\Requests\Admin\Invoices\ResendInvoiceRequest;
use App\Http\Services\Admin\AdminAudit;
use App\Http\Services\Admin\Invoices\InvoiceDirectory;
use App\Http\Services\Invoices\InvoicePdf;
use App\Http\Services\Invoices\InvoiceService;
use App\Models\CreditNote;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/** Superadmin view of invoices (admin.purchases.manage on the routes). Invoices are never edited here: only read, downloaded and re-sent. */
class InvoiceController extends Controller
{
    public function __construct(
        private readonly InvoiceDirectory $directory,
        private readonly InvoicePdf $pdf,
        private readonly InvoiceService $invoices,
        private readonly AdminAudit $audit,
    ) {}

    public function index(InvoiceIndexRequest $request): JsonResponse
    {
        return response()->json($this->directory->list($request->validated()));
    }

    public function show(Invoice $invoice): JsonResponse
    {
        return response()->json(['data' => $this->directory->detail($invoice)]);
    }

    public function pdf(Invoice $invoice): Response
    {
        return $this->download($invoice);
    }

    public function creditNotePdf(CreditNote $creditNote): Response
    {
        return $this->download($creditNote);
    }

    public function resend(ResendInvoiceRequest $request, Invoice $invoice): JsonResponse
    {
        $data = $request->validated();
        $to = $data['email'] ?? $invoice->recipient['email'];
        $this->invoices->deliver($invoice, $to);

        $this->audit->record('invoice.resend', ['invoice', $invoice->id], [
            'event_id' => $invoice->event_id, 'user_id' => $invoice->user_id, 'reason' => $data['reason'],
            'after' => ['number' => $invoice->number, 'sentTo' => $to],
        ]);

        return response()->json(['data' => $this->directory->detail($invoice->refresh())]);
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
