<?php

use App\Http\Services\Invoices\InvoicePdf;
use App\Http\Services\Invoices\InvoiceService;
use App\Http\Services\Payments\PurchaseService;
use App\Mail\InvoiceMail;
use App\Models\CreditNote;
use App\Models\EventPlan;
use App\Models\EventPurchase;
use App\Models\Events;
use App\Models\EventType;
use App\Models\Invoice;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    Storage::fake('local');
});

function pendingPurchase(array $attrs = [], ?User $buyer = null): EventPurchase
{
    $event = Events::factory()->draft()->create([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', 'basic')->value('id'),
    ]);
    $buyer ??= User::factory()->create();

    return EventPurchase::create($attrs + [
        'event_id' => $event->id, 'user_id' => $buyer->id, 'event_plan_id' => EventPlan::where('slug', 'premium')->value('id'),
        'gateway' => 'stripe', 'list_price_cents' => 9900, 'amount_cents' => 7900, 'status' => 'pending',
    ]);
}

it('issues one invoice with consecutive numbers per series and year', function () {
    Mail::fake();
    $service = app(PurchaseService::class);

    $a = $service->markPaid(pendingPurchase());
    $b = $service->markPaid(pendingPurchase());

    $year = now()->year;
    expect(Invoice::orderBy('id')->pluck('number')->all())->toBe([sprintf('QV-%d-000001', $year), sprintf('QV-%d-000002', $year)]);
    $first = Invoice::first();
    expect($first->purchase_id)->toBe($a->id)->and($first->total_cents)->toBe(7900)->and($first->subtotal_cents)->toBe(9900)
        ->and($first->discount_cents)->toBe(2000)->and($first->tax_cents)->toBe(0)->and($first->currency)->toBe('USD')
        ->and($first->issuer['name'])->toBe('[Razón social pendiente]');
    expect($b->invoices)->toHaveCount(1);
});

it('does not leave gaps when issuing fails and rolls back', function () {
    $service = app(InvoiceService::class);
    $paid = pendingPurchase(['status' => 'paid', 'paid_at' => now()]);
    $pending = pendingPurchase();

    try {
        DB::transaction(function () use ($service, $paid) {
            $service->issueForPurchase($paid);
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
    }
    expect(Invoice::count())->toBe(0);

    [$invoice] = $service->issueForPurchase($paid);
    expect($invoice->sequence)->toBe(1);
    expect(fn () => $service->issueForPurchase($pending))->toThrow(InvalidArgumentException::class);
});

it('is idempotent: confirming a payment twice issues and mails a single invoice', function () {
    Mail::fake();
    $service = app(PurchaseService::class);
    $purchase = pendingPurchase();

    $service->markPaid($purchase);
    $service->markPaid($purchase);
    [, $created] = app(InvoiceService::class)->issueForPurchase($purchase);

    expect(Invoice::count())->toBe(1)->and($created)->toBeFalse();
    Mail::assertSent(InvoiceMail::class, 1);
});

it('also invoices the manual payment of the admin', function () {
    Mail::fake();
    $buyer = User::factory()->create();
    $admin = User::factory()->create();
    $event = pendingPurchase()->event;

    $purchase = app(PurchaseService::class)->recordManual($event, $buyer, $admin, 9900, 'Zelle');

    $invoice = Invoice::firstOrFail();
    expect($invoice->purchase_id)->toBe($purchase->id)->and($invoice->payment_method)->toBe('manual')->and($invoice->discount_cents)->toBe(0);
    Mail::assertSent(InvoiceMail::class, fn (InvoiceMail $m) => $m->hasTo($buyer->email));
});

it('mails the invoice with the PDF attached, in the buyer language', function () {
    Mail::fake();
    $buyer = User::factory()->create();
    $buyer->preferences()->updateOrCreate([], ['language' => 'en']);
    app(PurchaseService::class)->markPaid(pendingPurchase([], $buyer->fresh()));

    Mail::assertSent(InvoiceMail::class, function (InvoiceMail $mail) {
        $attachments = $mail->attachments();
        $rendered = $mail->render();

        return $mail->invoice->locale === 'en' && count($attachments) === 1 && str_contains($rendered, 'Thank you for your purchase')
            && str_starts_with($attachments[0]->as, 'QV-');
    });
    expect(Invoice::first()->emailed_at)->not->toBeNull();
});

it('keeps the payment when issuing fails', function () {
    Mail::fake();
    config(['invoicing.series.invoice' => str_repeat('X', 40)]); // does not fit the column
    $purchase = app(PurchaseService::class)->markPaid(pendingPurchase());

    expect($purchase->status)->toBe('paid')->and(Invoice::count())->toBe(0);
});

it('computes the tax included in the charged amount from the configured rate', function () {
    Mail::fake();
    config(['invoicing.tax.rate' => 7.0]);
    app(PurchaseService::class)->markPaid(pendingPurchase(['amount_cents' => 10700, 'list_price_cents' => 10700]));

    $invoice = Invoice::first();
    expect($invoice->tax_cents)->toBe(700)->and($invoice->total_cents)->toBe(10700)->and($invoice->tax_rate)->toBe(7.0);
});

it('freezes the invoice: no edits, no deletes, and only issued to void', function () {
    Mail::fake();
    app(PurchaseService::class)->markPaid(pendingPurchase());
    $invoice = Invoice::first();

    expect(fn () => Invoice::first()->update(['total_cents' => 1]))->toThrow(LogicException::class);
    expect(fn () => Invoice::first()->update(['recipient' => ['name' => 'Other']]))->toThrow(LogicException::class);
    expect(fn () => Invoice::first()->delete())->toThrow(LogicException::class);
    $invoice = Invoice::first();
    $invoice->update(['emailed_at' => now()]); // bookkeeping fields are allowed
    $invoice->update(['status' => 'void', 'voided_at' => now()]);
    expect(fn () => Invoice::first()->update(['status' => 'issued']))->toThrow(LogicException::class);
});

it('refunds with a credit note, voids the invoice and keeps numbers consecutive', function () {
    Mail::fake();
    $service = app(PurchaseService::class);
    $purchase = $service->markPaid(pendingPurchase());
    $other = $service->markPaid(pendingPurchase());

    $service->refund($purchase, 'Asked for it');

    $invoice = Invoice::where('purchase_id', $purchase->id)->first();
    $note = CreditNote::firstOrFail();
    expect($invoice->status)->toBe('void')->and($invoice->void_reason)->toBe('Asked for it')
        ->and($note->number)->toBe(sprintf('NC-%d-000001', now()->year))->and($note->amount_cents)->toBe(7900)->and($note->invoice_id)->toBe($invoice->id);
    expect(fn () => $note->update(['amount_cents' => 1]))->toThrow(LogicException::class);
    expect(fn () => $note->delete())->toThrow(LogicException::class);
    expect(Invoice::where('purchase_id', $other->id)->first()->status)->toBe('issued');
});

it('renders the PDF in both languages and stores it privately', function () {
    Mail::fake();
    app(PurchaseService::class)->markPaid(pendingPurchase());
    $invoice = Invoice::first();
    $pdf = app(InvoicePdf::class);

    foreach (['es', 'en'] as $lang) {
        $invoice->locale = $lang;
        expect(substr($pdf->render($invoice), 0, 5))->toBe('%PDF-');
    }
    expect($invoice->fresh()->pdf_path)->not->toBeNull();
    Storage::disk('local')->assertExists($invoice->fresh()->pdf_path);
});

it('backfills the missing invoices of paid purchases', function () {
    Mail::fake();
    pendingPurchase(['status' => 'paid', 'paid_at' => now()]);
    pendingPurchase(['status' => 'refunded']);

    $this->artisan('invoices:backfill', ['--send' => true])->assertSuccessful();
    $this->artisan('invoices:backfill', ['--send' => true])->assertSuccessful();

    expect(Invoice::count())->toBe(1)->and(Invoice::first()->emailed_at)->not->toBeNull();
    Mail::assertSent(InvoiceMail::class, 1);
});
