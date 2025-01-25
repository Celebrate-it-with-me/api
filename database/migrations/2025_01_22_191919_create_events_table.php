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
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('event_name');
            $table->string('event_description');
            $table->timestamp('event_date');
            $table->unsignedBigInteger('organizer_id');
            $table->enum('status', ['draft', 'published', 'archived', 'canceled'])->default('draft');
            $table->string('custom_url_slug');
            $table->enum('visibility', ['public', 'private', 'restricted'])->default('private');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
