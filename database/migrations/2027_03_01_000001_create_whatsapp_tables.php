<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** WhatsApp (docs/plans/communications-contract.md): templates, messages and the idempotency table of the webhook. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40);
            $table->string('locale', 5);
            $table->string('meta_name', 80);
            $table->string('meta_template_id', 60)->nullable();
            $table->string('category', 20)->default('utility');
            $table->text('body');
            $table->json('buttons');
            $table->json('variables');
            $table->string('status', 20)->default('draft');
            $table->text('rejection_reason')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['key', 'locale']);
            $table->index('meta_name');
        });

        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->nullable()->constrained('events')->nullOnDelete();
            $table->foreignId('guest_id')->nullable()->constrained('guests')->nullOnDelete();
            $table->uuid('batch_id')->nullable()->index();
            $table->string('template_key', 40);
            $table->string('locale', 5);
            $table->string('type', 10)->default('template'); // template | text (session reply)
            $table->string('to_phone', 20);
            $table->json('params')->nullable();
            $table->text('body')->nullable(); // session text only
            $table->string('status', 12)->default('queued');
            $table->string('error_code', 40)->nullable();
            $table->text('error_message')->nullable();
            $table->string('wa_message_id', 120)->nullable()->index();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
            $table->index(['event_id', 'status']);
            $table->index(['guest_id', 'template_key', 'queued_at']);
            $table->index('to_phone');
        });

        Schema::create('whatsapp_inbound_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_key', 190)->unique();
            $table->string('kind', 30);
            $table->timestamp('created_at')->nullable();
        });

        Schema::table('guests', function (Blueprint $table) {
            $table->timestamp('whatsapp_opted_out_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('guests', fn (Blueprint $table) => $table->dropColumn('whatsapp_opted_out_at'));
        Schema::dropIfExists('whatsapp_inbound_events');
        Schema::dropIfExists('whatsapp_messages');
        Schema::dropIfExists('whatsapp_templates');
    }
};
