<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Invoices (docs/plans/invoices.md, A14). Own numbering (one counter per series and year, locked while it is read so
 * there are no gaps), immutable documents, credit notes for refunds, and the buyer's billing data.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Last number handed out per series and year. The row is locked (SELECT ... FOR UPDATE) inside the issuing transaction.
        Schema::create('invoice_counters', function (Blueprint $table) {
            $table->id();
            $table->string('series', 10);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
            $table->unique(['series', 'year']);
        });

        Schema::create('billing_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('country', 2)->nullable();
            $table->string('tax_id')->nullable();
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();                       // QV-2026-000001
            $table->string('series', 10);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('sequence');
            $table->foreignId('purchase_id')->constrained('event_purchases')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('event_id')->constrained('events')->restrictOnDelete();
            $table->string('status', 10)->default('issued');          // issued | void
            $table->json('issuer');                                   // copy at issue time
            $table->json('recipient');                                // copy at issue time
            $table->json('lines');
            $table->unsignedInteger('subtotal_cents');
            $table->unsignedInteger('discount_cents')->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->unsignedInteger('tax_cents')->default(0);        // already included in total_cents
            $table->unsignedInteger('total_cents');
            $table->string('currency', 3);
            $table->string('payment_method', 20);                     // stripe | manual | fake
            $table->string('payment_reference')->nullable();
            $table->string('locale', 2)->default('es');
            $table->timestamp('issued_at');
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 500)->nullable();
            $table->string('pdf_path')->nullable();
            $table->timestamp('emailed_at')->nullable();
            $table->timestamps();
            $table->unique(['series', 'year', 'sequence']);
            $table->index(['user_id', 'issued_at']);
        });

        // One live invoice per purchase: what makes issuing twice impossible even under concurrency.
        DB::statement("CREATE UNIQUE INDEX invoices_one_issued_per_purchase ON invoices (purchase_id) WHERE status = 'issued'");

        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();                       // NC-2026-000001
            $table->string('series', 10);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('sequence');
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->foreignId('purchase_id')->constrained('event_purchases')->restrictOnDelete();
            $table->string('reason', 500);
            $table->unsignedInteger('amount_cents');                  // refunded, tax included
            $table->unsignedInteger('tax_cents')->default(0);
            $table->string('currency', 3);
            $table->string('locale', 2)->default('es');
            $table->timestamp('issued_at');
            $table->string('pdf_path')->nullable();
            $table->timestamps();
            $table->unique(['series', 'year', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_notes');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('billing_profiles');
        Schema::dropIfExists('invoice_counters');
    }
};
