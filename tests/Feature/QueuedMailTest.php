<?php

use App\Http\Services\Invoices\InvoiceService;
use App\Http\Services\Payments\PurchaseService;
use App\Jobs\DeliverCreditNoteEmail;
use App\Jobs\DeliverInvoiceEmail;
use App\Mail\InvoiceMail;
use App\Mail\ResetPasswordEmail;
use App\Models\EventPlan;
use App\Models\EventPurchase;
use App\Models\Events;
use App\Models\EventType;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Mail\Outbox;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    Storage::fake('local');
});

function queuedPurchase(): EventPurchase
{
    $event = Events::factory()->draft()->create([
        'event_type_id' => EventType::where('slug', 'quinceanera')->value('id'),
        'event_plan_id' => EventPlan::where('slug', 'basic')->value('id'),
    ]);

    return EventPurchase::create([
        'event_id' => $event->id, 'user_id' => User::factory()->create()->id, 'event_plan_id' => EventPlan::where('slug', 'premium')->value('id'),
        'gateway' => 'fake', 'list_price_cents' => 9900, 'amount_cents' => 7900, 'status' => 'pending',
    ]);
}

describe('mail out of the request', function () {
    it('sends right away on the sync connection (tests, a box without a worker)', function () {
        Mail::fake();
        $user = User::factory()->create();

        Outbox::send($user->email, new ResetPasswordEmail($user, 'https://app.test/x'));

        Mail::assertSent(ResetPasswordEmail::class);
        Mail::assertNothingQueued();
    });

    it('queues with retries and backoff on a real queue, and sends nothing in the request', function () {
        config(['queue.default' => 'redis']);
        Queue::fake();
        $user = User::factory()->create();

        Outbox::send($user->email, new ResetPasswordEmail($user, 'https://app.test/x'));

        Queue::assertPushed(SendQueuedMailable::class, fn (SendQueuedMailable $job) => $job->tries === Outbox::TRIES
            && $job->backoff() === Outbox::BACKOFF && $job->afterCommit === true);
    });

    it('queues the reset-password mail of the public endpoint', function () {
        config(['queue.default' => 'redis']);
        Queue::fake();
        $user = User::factory()->create();

        $this->postJson('/api/v1/app/forgot-password', ['email' => $user->email])->assertOk();

        Queue::assertPushed(SendQueuedMailable::class);
    });
});

describe('invoice delivery job', function () {
    it('is queued after a payment on a real queue and not run in the request', function () {
        config(['queue.default' => 'redis']);
        Queue::fake();
        Mail::fake();

        app(PurchaseService::class)->markPaid(queuedPurchase());

        Queue::assertPushed(DeliverInvoiceEmail::class);
        Mail::assertNothingSent();
        expect(Invoice::first()->emailed_at)->toBeNull();
    });

    it('mails once even if the job runs twice, and a staff resend always goes out', function () {
        Mail::fake();
        Queue::fake();
        app(PurchaseService::class)->markPaid(queuedPurchase());
        $invoice = Invoice::first();

        $run = fn (DeliverInvoiceEmail $job) => $job->handle(app(InvoiceService::class));
        $run(new DeliverInvoiceEmail($invoice->id));
        $run(new DeliverInvoiceEmail($invoice->id));
        Mail::assertSent(InvoiceMail::class, 1);

        $run(new DeliverInvoiceEmail($invoice->id, 'otra@example.com', resend: true));
        Mail::assertSent(InvoiceMail::class, 2);
    });

    it('queues the credit note of a refund', function () {
        config(['queue.default' => 'redis']);
        Queue::fake();
        Mail::fake();
        $service = app(PurchaseService::class);
        $purchase = $service->markPaid(queuedPurchase());
        Queue::fake();

        $service->refund($purchase->fresh(), 'test');

        Queue::assertPushed(DeliverCreditNoteEmail::class);
    });
});
