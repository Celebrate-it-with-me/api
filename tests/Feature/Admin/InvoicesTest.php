<?php

use App\Http\Services\Payments\PurchaseService;
use App\Mail\InvoiceMail;
use App\Models\AdminAuditLog;
use App\Models\EventPlan;
use App\Models\EventPurchase;
use App\Models\Events;
use App\Models\EventType;
use App\Models\Invoice;
use App\Models\User;
use App\Support\AdminPermissions;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    User::role(AdminPermissions::STAFF_ROLES)->get()->each(fn (User $u) => $u->syncRoles([]));
    Storage::fake('local');
    Mail::fake();
});

function invStaff(string $role): User
{
    $u = User::factory()->create();
    $u->assignRole($role);

    return $u;
}

function paidInvoice(string $eventName = 'Los XV de Ana', ?User $buyer = null, int $amount = 7900): Invoice
{
    $event = Events::factory()->draft()->create([
        'event_name' => $eventName, 'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', 'basic')->value('id'),
    ]);
    $buyer ??= User::factory()->create();
    $purchase = EventPurchase::create([
        'event_id' => $event->id, 'user_id' => $buyer->id, 'event_plan_id' => EventPlan::where('slug', 'premium')->value('id'),
        'gateway' => 'stripe', 'list_price_cents' => 9900, 'amount_cents' => $amount, 'status' => 'pending', 'gateway_payment_id' => 'pi_3Abc123SECRETXYZ9',
    ]);
    app(PurchaseService::class)->markPaid($purchase);

    return Invoice::where('purchase_id', $purchase->id)->firstOrFail();
}

it('keeps everyone but superadmin out of every invoice route', function () {
    $i = paidInvoice();
    $routes = [
        ['get', '/api/v1/admin/invoices'], ['get', "/api/v1/admin/invoices/{$i->id}"], ['get', "/api/v1/admin/invoices/{$i->id}/pdf"],
        ['post', "/api/v1/admin/invoices/{$i->id}/resend"],
    ];

    foreach ($routes as [$method, $url]) {
        $this->{$method . 'Json'}($url)->assertUnauthorized();
    }
    foreach ($routes as [$method, $url]) {
        $this->actingAs(User::factory()->create())->{$method . 'Json'}($url)->assertForbidden();
        $this->actingAs(invStaff('support'))->{$method . 'Json'}($url)->assertForbidden();
    }
    $this->actingAs(invStaff('superadmin'))->getJson('/api/v1/admin/invoices')->assertOk();
});

it('lists with filters and does not run more queries as it grows', function () {
    $admin = invStaff('superadmin');
    $a = paidInvoice('Los XV de Ana', User::factory()->create(['name' => 'Marisol Perez', 'email' => 'marisol@example.com']));
    paidInvoice('Boda de Luz');
    $a->forceFill(['status' => 'void'])->save();

    $this->actingAs($admin)->getJson('/api/v1/admin/invoices')->assertJsonPath('meta.total', 2);
    $this->actingAs($admin)->getJson('/api/v1/admin/invoices?status=void')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.number', $a->number);
    $this->actingAs($admin)->getJson('/api/v1/admin/invoices?search=MARISOL')->assertJsonPath('meta.total', 1);
    $this->actingAs($admin)->getJson('/api/v1/admin/invoices?search=' . $a->number)->assertJsonPath('meta.total', 1);
    $this->actingAs($admin)->getJson('/api/v1/admin/invoices?from=2999-01-01')->assertJsonPath('meta.total', 0);
    $this->actingAs($admin)->getJson('/api/v1/admin/invoices?status=bogus')->assertStatus(422);

    $count = function () use ($admin) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)->getJson('/api/v1/admin/invoices?perPage=50')->assertOk();

        return count(DB::getQueryLog());
    };
    $count();
    $small = $count();
    foreach (range(1, 6) as $n) {
        paidInvoice("Evento {$n}");
    }
    expect($count())->toBe($small);
});

it('shows the detail with credit notes and no whole payment reference', function () {
    $admin = invStaff('superadmin');
    $i = paidInvoice();
    $i->purchase->update(['status' => 'paid']);
    app(PurchaseService::class)->refund($i->purchase, 'Asked for it');

    $res = $this->actingAs($admin)->getJson("/api/v1/admin/invoices/{$i->id}")->assertOk()
        ->assertJsonPath('data.status', 'void')->assertJsonPath('data.voidReason', 'Asked for it')
        ->assertJsonPath('data.creditNotes.0.amountCents', 7900)->assertJsonPath('data.paymentReference', '…XYZ9')
        ->assertJsonPath('data.totalCents', 7900)->assertJsonPath('data.issuer.name', '[Razón social pendiente]');
    expect($res->getContent())->not->toContain('pi_3Abc123');
    $this->actingAs($admin)->getJson('/api/v1/admin/invoices/999999')->assertNotFound();

    $purchase = $this->actingAs($admin)->getJson("/api/v1/admin/revenue/purchases/{$i->purchase_id}")->assertOk();
    expect($purchase->json('data.invoices.0.number'))->toBe($i->number);
});

it('downloads the PDF of an invoice and of its credit note', function () {
    $admin = invStaff('superadmin');
    $i = paidInvoice();
    app(PurchaseService::class)->refund($i->purchase, 'Asked for it');
    $note = $i->creditNotes()->first();

    $res = $this->actingAs($admin)->get("/api/v1/admin/invoices/{$i->id}/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
    expect(substr($res->getContent(), 0, 5))->toBe('%PDF-')->and($res->headers->get('content-disposition'))->toContain($i->number . '.pdf');
    $this->actingAs($admin)->get("/api/v1/admin/invoices/credit-notes/{$note->id}/pdf")->assertOk()
        ->assertHeader('content-disposition', 'attachment; filename="' . $note->number . '.pdf"');
});

it('resends the invoice, audited, with a reason, to the buyer or another address', function () {
    $admin = invStaff('superadmin');
    $i = paidInvoice(buyer: User::factory()->create(['email' => 'buyer@example.com']));
    Mail::fake();

    $this->actingAs($admin)->postJson("/api/v1/admin/invoices/{$i->id}/resend", [])->assertStatus(422)->assertJsonValidationErrors('reason');
    $this->actingAs($admin)->postJson("/api/v1/admin/invoices/{$i->id}/resend", ['reason' => 'Lost it', 'email' => 'nope'])->assertStatus(422);

    $this->actingAs($admin)->postJson("/api/v1/admin/invoices/{$i->id}/resend", ['reason' => 'Client lost it'])->assertOk();
    $this->actingAs($admin)->postJson("/api/v1/admin/invoices/{$i->id}/resend", ['reason' => 'Accountant copy', 'email' => 'acct@example.com'])->assertOk();

    Mail::assertSent(InvoiceMail::class, fn (InvoiceMail $m) => $m->hasTo('buyer@example.com'));
    Mail::assertSent(InvoiceMail::class, fn (InvoiceMail $m) => $m->hasTo('acct@example.com'));
    $logs = AdminAuditLog::where('action', 'invoice.resend')->get();
    expect($logs)->toHaveCount(2)->and($logs[0]->actor_id)->toBe($admin->id)->and($logs[0]->subject_type)->toBe('invoice')
        ->and($logs[0]->subject_id)->toBe($i->id)->and($logs[1]->reason)->toBe('Accountant copy');
});

it('has no way to edit or delete an invoice through the API', function () {
    $admin = invStaff('superadmin');
    $i = paidInvoice();

    $this->actingAs($admin)->putJson("/api/v1/admin/invoices/{$i->id}", ['totalCents' => 1])->assertStatus(405);
    $this->actingAs($admin)->deleteJson("/api/v1/admin/invoices/{$i->id}")->assertStatus(405);
});
