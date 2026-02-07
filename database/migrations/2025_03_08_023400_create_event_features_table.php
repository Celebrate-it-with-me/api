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
        Schema::create('event_features', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('event_id');
            $table->boolean('save_the_date')->default(false);
            $table->boolean('rsvp')->default(false);
            $table->boolean('gallery')->default(false);
            $table->boolean('music')->default(false);
            $table->boolean('seats_accommodation')->default(false);
            $table->boolean('preview')->default(false);
            $table->boolean('budget')->default(false);
            $table->boolean('analytics')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_features');
    }
};
