<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The SMS reminders table was only read by a never-finished command
     * (no writer, no schedule). SMS is no longer sent from the API.
     */
    public function up(): void
    {
        Schema::dropIfExists('sms_reminders');
    }

    public function down(): void
    {
        Schema::create('sms_reminders', function (Blueprint $table) {
            $table->id();
            $table->json('recipients');
            $table->timestamp('send_date');
            $table->string('message');
            $table->boolean('alreadySent')->default(false);
            $table->timestamps();
        });
    }
};
