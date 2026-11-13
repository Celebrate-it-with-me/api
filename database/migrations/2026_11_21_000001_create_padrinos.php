<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('padrinos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            // The padrino may also be on the guest list; optional link.
            $table->foreignId('guest_id')->nullable()->constrained('guests')->nullOnDelete();
            $table->string('name', 120);
            $table->string('phone', 30)->nullable();
            $table->string('relationship', 80)->nullable();
            // invited | accepted: set by hand. How much they paid comes from the payments, never from here.
            $table->string('status', 12)->default('invited');
            // Premium: shown in the public invitation ("Con el cariño de").
            $table->boolean('public_thanks')->default(false);
            $table->string('thanks_text', 300)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // What a padrino promised to cover, per budget item.
        Schema::create('padrino_commitments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('padrino_id')->constrained('padrinos')->cascadeOnDelete();
            $table->foreignId('budget_item_id')->constrained('budget_items')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->timestamps();
            $table->unique(['padrino_id', 'budget_item_id']);
        });

        // A payment made by a padrino points to them; one with a payment cannot be deleted.
        Schema::table('budget_payments', function (Blueprint $table) {
            $table->foreign('padrino_id')->references('id')->on('padrinos')->restrictOnDelete();
        });

        // Padrinos goes live: it was in the catalog, switched off until it existed.
        DB::table('modules')->where('key', 'padrinos')->update(['is_enabled' => true]);
    }

    public function down(): void
    {
        Schema::table('budget_payments', fn (Blueprint $table) => $table->dropForeign(['padrino_id']));
        Schema::dropIfExists('padrino_commitments');
        Schema::dropIfExists('padrinos');
        DB::table('modules')->where('key', 'padrinos')->update(['is_enabled' => false]);
    }
};
