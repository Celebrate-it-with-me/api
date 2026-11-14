<?php

use App\Http\Services\Invoices\InvoiceService;
use App\Http\Services\Payments\PurchaseService;
use App\Mail\CreditNoteMail;
use App\Mail\InvoiceMail;
use App\Models\BillingProfile;
use App\Models\CreditNote;
use App\Models\EventPlan;
use App\Models\EventPurchase;
use App\Models\Events;
use App\Models\EventType;
use App\Models\Invoice;
use App\Models\User;
use App\Support\AdminPermissions;
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

function billingPurchase(User $buyer): EventPurchase
{
    $event = Events::factory()->draft()->create([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', 'basic')->value('id'),
    ]);

    return EventPurchase::create([
        'event_id' => $event->id, 'user_id' => $buyer->id, 'event_plan_id' => EventPlan::where('slug', 'premium')->value('id'),
        'gateway' => 'stripe', 'list_price_cents' => 9900, 'amount_cents' => 9900, 'status' => 'pending',
    ]);
}

function billingInvoice(User $buyer): Invoice
{
    $purchase = app(PurchaseService::class)->markPaid(billingPurchase($buyer));

    return Invoice::where('purchase_id', $purchase->id)->firstOrFail();
}

function validProfile(array $over = []): array
{
    return $over + [
        'name' => 'Marisol Pérez', 'billingEmail' => 'facturas@example.com', 'address' => '123 Brickell Ave', 'city' => 'Miami',
        'state' => 'FL', 'postalCode' => '33131', 'country' => 'us', 'taxId' => 'EIN 12-3456789',
    ];
}

const BP_URL = '/api/v1/app/account/billing-profile';

describe('billing profile', function () {
    it('requires login', function () {
        $this->getJson(BP_URL)->assertUnauthorized();
        $this->putJson(BP_URL, validProfile())->assertUnauthorized();
    });

    it('returns an empty shape before it exists and then her saved data', function () {
        $me = User::factory()->create();
        $this->actingAs($me)->getJson(BP_URL)->assertOk()->assertJsonPath('data.exists', false)->assertJsonPath('data.accountEmail', $me->email);

        $this->actingAs($me)->putJson(BP_URL, validProfile())->assertOk()->assertJsonPath('data.country', 'US')->assertJsonPath('data.taxId', 'EIN 12-3456789');
        $this->actingAs($me)->getJson(BP_URL)->assertOk()->assertJsonPath('data.exists', true)->assertJsonPath('data.billingEmail', 'facturas@example.com');
        expect(BillingProfile::count())->toBe(1);

        $this->actingAs($me)->putJson(BP_URL, validProfile(['name' => 'Marisol P.', 'taxId' => null, 'address' => null]))->assertOk();
        expect(BillingProfile::count())->toBe(1)->and(BillingProfile::first()->name)->toBe('Marisol P.')->and(BillingProfile::first()->tax_id)->toBeNull();
    });

    it('only ever reads and writes her own row', function () {
        $me = User::factory()->create();
        $other = User::factory()->create();
        BillingProfile::create(['user_id' => $other->id, 'name' => 'Secret Co', 'billing_email' => 'x@example.com', 'country' => 'US', 'tax_id' => 'SECRET-123']);

        $res = $this->actingAs($me)->getJson(BP_URL)->assertOk();
        expect(json_encode($res->json()))->not->toContain('SECRET-123')->not->toContain('Secret Co');

        // A user_id smuggled into the body is ignored.
        $this->actingAs($me)->putJson(BP_URL, validProfile(['user_id' => $other->id, 'userId' => $other->id]))->assertOk();
        expect(BillingProfile::where('user_id', $other->id)->first()->name)->toBe('Secret Co')
            ->and(BillingProfile::where('user_id', $me->id)->first()->name)->toBe('Marisol Pérez');
    });

    it('validates the data', function (array $bad, string $field) {
        $me = User::factory()->create();
        $this->actingAs($me)->putJson(BP_URL, validProfile($bad))->assertUnprocessable()->assertJsonValidationErrors($field);
    })->with([
        'no name' => [['name' => ''], 'name'],
        'bad email' => [['billingEmail' => 'nope'], 'billingEmail'],
        'no email' => [['billingEmail' => null], 'billingEmail'],
        'country too long' => [['country' => 'USA'], 'country'],
        'country digits' => [['country' => '12'], 'country'],
        'tax id with symbols' => [['taxId' => '<script>'], 'taxId'],
        'tax id too short' => [['taxId' => 'a'], 'taxId'],
        'postal code with symbols' => [['postalCode' => '33131;--'], 'postalCode'],
    ]);

    it('copies the data onto the invoice only when it is issued: adding data later does not fix old invoices', function () {
        $me = User::factory()->create();
        $before = billingInvoice($me);
        expect($before->recipient['tax_id'])->toBeNull()->and($before->recipient['name'])->toBe($me->name);

        $this->actingAs($me)->putJson(BP_URL, validProfile())->assertOk();
        expect($before->refresh()->recipient['tax_id'])->toBeNull()->and($before->recipient['email'])->toBe($me->email);

        $after = billingInvoice($me);
        expect($after->recipient)->toMatchArray(['name' => 'Marisol Pérez', 'email' => 'facturas@example.com', 'tax_id' => 'EIN 12-3456789', 'country' => 'US']);
        Mail::assertSent(InvoiceMail::class, fn ($m) => $m->hasTo('facturas@example.com'));
    });
});

describe('credit note mail', function () {
    it('is sent once to the invoice email when the purchase is refunded, with the PDF attached', function () {
        $me = User::factory()->create();
        $invoice = billingInvoice($me);

        app(PurchaseService::class)->refund($invoice->purchase, 'Changed plans');

        Mail::assertSent(CreditNoteMail::class, 1);
        Mail::assertSent(CreditNoteMail::class, function (CreditNoteMail $m) use ($me) {
            $attachment = $m->attachments()[0];

            return $m->hasTo($me->email) && str_starts_with($m->render(), '<') && $attachment !== null;
        });
        $note = CreditNote::firstOrFail();
        expect($note->emailed_at)->not->toBeNull();
    });

    it('is idempotent: delivering again sends nothing', function () {
        $invoice = billingInvoice(User::factory()->create());
        app(PurchaseService::class)->refund($invoice->purchase, 'x');
        $note = CreditNote::firstOrFail();

        expect(app(InvoiceService::class)->deliverCreditNote($note))->toBeFalse();
        Mail::assertSent(CreditNoteMail::class, 1);
    });

    it('does not undo the refund when the mail server fails, and can be retried', function () {
        $invoice = billingInvoice($me = User::factory()->create());
        Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp down'));

        $purchase = app(PurchaseService::class)->refund($invoice->purchase, 'x');

        expect($purchase->status)->toBe('refunded')->and($invoice->refresh()->status)->toBe('void');
        $note = CreditNote::firstOrFail();
        expect($note->emailed_at)->toBeNull();
    });

    it('renders in both languages', function () {
        $user = User::factory()->create();
        $invoice = billingInvoice($user);
        app(PurchaseService::class)->refund($invoice->purchase, 'x');
        $note = CreditNote::firstOrFail();
        foreach (['es' => 'nota de crédito', 'en' => 'credit note'] as $lang => $needle) {
            $note->locale = $lang;
            expect(mb_strtolower((new CreditNoteMail($note))->render()))->toContain($needle);
        }
    });
});

describe('resend from My purchases', function () {
    it('sends to her billing or account address, never to anyone else', function () {
        $me = User::factory()->create();
        $invoice = billingInvoice($me);
        $this->actingAs($me)->putJson(BP_URL, validProfile())->assertOk();
        Mail::fake();

        $this->actingAs($me)->postJson("/api/v1/app/account/invoices/{$invoice->id}/resend", ['to' => 'billing'])->assertOk()->assertJsonPath('data.sentTo', 'facturas@example.com');
        $this->actingAs($me)->postJson("/api/v1/app/account/invoices/{$invoice->id}/resend", ['to' => 'account'])->assertOk()->assertJsonPath('data.sentTo', $me->email);
        Mail::assertSent(InvoiceMail::class, 2);

        // Free-form addresses are not accepted.
        $this->actingAs($me)->postJson("/api/v1/app/account/invoices/{$invoice->id}/resend", ['to' => 'evil@example.com'])->assertUnprocessable();
        $this->actingAs($me)->postJson("/api/v1/app/account/invoices/{$invoice->id}/resend", ['to' => 'account', 'email' => 'evil@example.com'])->assertOk()->assertJsonPath('data.sentTo', $me->email);
        Mail::assertNotSent(InvoiceMail::class, fn ($m) => $m->hasTo('evil@example.com'));
    });

    it('asks for a billing email first when she has none', function () {
        $me = User::factory()->create();
        $invoice = billingInvoice($me);
        $this->actingAs($me)->postJson("/api/v1/app/account/invoices/{$invoice->id}/resend", ['to' => 'billing'])->assertUnprocessable();
    });

    it('refuses other people (403) and unknown ids (404), for invoices and credit notes', function () {
        $me = User::factory()->create();
        $invoice = billingInvoice($me);
        app(PurchaseService::class)->refund($invoice->purchase, 'x');
        $note = CreditNote::firstOrFail();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->postJson("/api/v1/app/account/invoices/{$invoice->id}/resend", ['to' => 'account'])->assertForbidden();
        $this->actingAs($stranger)->postJson("/api/v1/app/account/credit-notes/{$note->id}/resend", ['to' => 'account'])->assertForbidden();
        $this->actingAs($me)->postJson('/api/v1/app/account/invoices/999999/resend', ['to' => 'account'])->assertNotFound();
        $this->actingAs($me)->postJson("/api/v1/app/account/credit-notes/{$note->id}/resend", ['to' => 'account'])->assertOk();
    });

    it('is throttled', function () {
        $me = User::factory()->create();
        $invoice = billingInvoice($me);
        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($me)->postJson("/api/v1/app/account/invoices/{$invoice->id}/resend", ['to' => 'account'])->assertOk();
        }
        $this->actingAs($me)->postJson("/api/v1/app/account/invoices/{$invoice->id}/resend", ['to' => 'account'])->assertStatus(429);
    });
});

describe('admin revenue detail', function () {
    it('shows invoices with their credit notes and no fiscal data', function () {
        $me = User::factory()->create();
        $this->actingAs($me)->putJson(BP_URL, validProfile(['taxId' => 'TOPSECRET-77']))->assertOk();
        $invoice = billingInvoice($me);
        app(PurchaseService::class)->refund($invoice->purchase, 'x');

        $admin = User::factory()->create();
        $admin->assignRole(AdminPermissions::SUPERADMIN);
        $res = $this->actingAs($admin)->getJson("/api/v1/admin/revenue/purchases/{$invoice->purchase_id}")->assertOk();
        expect($res->json('data.invoices.0.creditNotes.0.number'))->toStartWith('NC-')
            ->and($res->json('data.invoices.0.status'))->toBe('void')
            ->and(json_encode($res->json()))->not->toContain('TOPSECRET-77');
    });
});
