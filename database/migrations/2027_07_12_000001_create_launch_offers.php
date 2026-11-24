<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The launch offer: a reduced price for the first N paid events, shown without a code. One row per plan.
        // Used slots are NOT stored: they are the paid purchases that carry `launch_offer_id` (so a refund or a chargeback
        // frees the slot by itself and a repeated webhook cannot count twice) plus `used_adjustment`, an admin correction.
        Schema::create('launch_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_plan_id')->unique()->constrained('event_plans')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('price_cents');
            $table->unsignedInteger('total_slots');
            $table->integer('used_adjustment')->default(0);
            $table->timestamps();
        });

        Schema::table('event_purchases', function (Blueprint $table) {
            $table->foreignId('launch_offer_id')->nullable()->after('coupon_id')->constrained('launch_offers')->nullOnDelete();
            $table->index(['launch_offer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('event_purchases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('launch_offer_id');
        });
        Schema::dropIfExists('launch_offers');
    }
};
