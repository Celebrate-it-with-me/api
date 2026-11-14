<?php

use App\Http\Services\Payments\PurchaseService;
use App\Models\EventPlan;
use App\Models\EventPurchase;
use App\Models\Events;
use App\Models\EventType;
use App\Models\Invoice;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    Storage::fake('local');
    Mail::fake();
});

function myInvoice(User $buyer, string $name = 'Los XV de Ana'): Invoice
{
    $event = Events::factory()->draft()->create([
        'event_name' => $name, 'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', 'basic')->value('id'),
    ]);
    $purchase = EventPurchase::create([
        'event_id' => $event->id, 'user_id' => $buyer->id, 'event_plan_id' => EventPlan::where('slug', 'premium')->value('id'),
        'gateway' => 'stripe', 'list_price_cents' => 9900, 'amount_cents' => 9900, 'status' => 'pending',
    ]);
    app(PurchaseService::class)->markPaid($purchase);

    return Invoice::where('purchase_id', $purchase->id)->firstOrFail();
}

it('requires login', function () {
    $this->getJson('/api/v1/app/account/purchases')->assertUnauthorized();
    $this->getJson('/api/v1/app/account/invoices/1/pdf')->assertUnauthorized();
});

it('lists only the purchases of the logged in user, with their invoices and credit notes', function () {
    $me = User::factory()->create();
    $mine = myInvoice($me, 'Mi quince');
    $refunded = myInvoice($me, 'Mi boda');
    app(PurchaseService::class)->refund($refunded->purchase, 'Changed plans');
    myInvoice(User::factory()->create(), 'De otra');

    $res = $this->actingAs($me)->getJson('/api/v1/app/account/purchases')->assertOk()->assertJsonCount(2, 'data');
    $names = collect($res->json('data'))->pluck('event.name')->all();
    expect($names)->toContain('Mi quince')->toContain('Mi boda')->not->toContain('De otra');
    $row = collect($res->json('data'))->firstWhere('event.name', 'Mi boda');
    expect($row['status'])->toBe('refunded')->and($row['invoices'][0]['status'])->toBe('void')->and($row['invoices'][0]['creditNotes'])->toHaveCount(1);
    expect(collect($res->json('data'))->firstWhere('event.name', 'Mi quince')['invoices'][0]['number'])->toBe($mine->number);
});

it('serves the PDF to its owner and refuses everyone else', function () {
    $me = User::factory()->create();
    $mine = myInvoice($me);
    $refunded = myInvoice($me, 'Otra');
    app(PurchaseService::class)->refund($refunded->purchase, 'Changed plans');
    $note = $refunded->creditNotes()->first();
    $stranger = User::factory()->create();

    $res = $this->actingAs($me)->get("/api/v1/app/account/invoices/{$mine->id}/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
    expect(substr($res->getContent(), 0, 5))->toBe('%PDF-');
    $this->actingAs($me)->get("/api/v1/app/account/credit-notes/{$note->id}/pdf")->assertOk();

    $this->actingAs($stranger)->get("/api/v1/app/account/invoices/{$mine->id}/pdf")->assertForbidden();
    $this->actingAs($stranger)->get("/api/v1/app/account/credit-notes/{$note->id}/pdf")->assertForbidden();
    $this->actingAs($me)->get('/api/v1/app/account/invoices/999999/pdf')->assertNotFound();
});
