<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_plans', function (Blueprint $table) {
            $table->unsignedInteger('price_cents')->default(0)->after('max_guests');
            $table->string('currency', 3)->default('USD')->after('price_cents');
        });

        // Fixed-price codes (founder events) and discounts. The admin creates them.
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('kind', 20)->default('fixed_price'); // fixed_price | percent_off
            $table->unsignedInteger('value');                    // cents for fixed_price, 1-100 for percent_off
            $table->boolean('is_founder')->default(false);
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('times_used')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('event_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('event_plan_id')->constrained('event_plans')->restrictOnDelete();
            $table->foreignId('coupon_id')->nullable()->constrained('coupons')->nullOnDelete();
            $table->string('gateway', 20);                        // fake | stripe | manual
            $table->string('gateway_session_id')->nullable()->unique();
            $table->string('gateway_payment_id')->nullable();
            $table->unsignedInteger('list_price_cents');
            $table->unsignedInteger('amount_cents');              // what was charged
            $table->string('currency', 3)->default('USD');
            $table->string('status', 20)->default('pending');     // pending | paid | refunded | failed
            $table->boolean('is_founder')->default(false);
            $table->string('receipt_url')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['event_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_purchases');
        Schema::dropIfExists('coupons');
        Schema::table('event_plans', function (Blueprint $table) {
            $table->dropColumn(['price_cents', 'currency']);
        });
    }
};
