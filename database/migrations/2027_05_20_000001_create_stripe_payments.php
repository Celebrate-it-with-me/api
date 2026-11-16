<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Stripe checkout (docs/plans/stripe-checkout-contract-draft.md, §3): gateway ids, failure/dispute state and the webhook log. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_purchases', function (Blueprint $table) {
            $table->string('gateway_refund_id')->nullable()->after('gateway_payment_id');
            $table->string('refund_reason', 500)->nullable()->after('refunded_at');
            $table->string('failure_code', 40)->nullable();
            $table->timestamp('session_expires_at')->nullable();
            $table->unsignedInteger('tax_cents')->default(0);
            $table->string('dispute_id')->nullable();
            $table->string('dispute_status', 30)->nullable();
            $table->timestamp('disputed_at')->nullable();
            $table->boolean('needs_review')->default(false);
            $table->index('gateway_payment_id');
        });

        // Idempotency and log of the gateway's webhooks. The body is not kept (it carries the cardholder's name and e-mail).
        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 20);
            $table->string('event_id')->unique();
            $table->string('type', 100);
            $table->foreignId('purchase_id')->nullable()->constrained('event_purchases')->nullOnDelete();
            $table->string('outcome', 20); // processed | ignored | mismatch | error
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');

        Schema::table('event_purchases', function (Blueprint $table) {
            $table->dropIndex(['gateway_payment_id']);
            $table->dropColumn([
                'gateway_refund_id', 'refund_reason', 'failure_code', 'session_expires_at', 'tax_cents',
                'dispute_id', 'dispute_status', 'disputed_at', 'needs_review',
            ]);
        });
    }
};
