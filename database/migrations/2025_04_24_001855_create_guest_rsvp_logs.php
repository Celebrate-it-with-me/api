<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('guest_rsvp_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guest_id')->constrained('guests');
            $table->string('status'); // confirmed, declined, pending
            $table->timestamp('changed_at')->nullable(); // When the status was changed
            $table->string('changed_by')->nullable(); // Who changed the status admin, guest, system
            $table->text('notes')->nullable(); // Optional notes about the change
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('guest_rsvp_logs');
    }
};
