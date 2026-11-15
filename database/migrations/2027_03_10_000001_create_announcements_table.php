<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** In-app announcements written by the staff (docs/plans/announcements-contract.md). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('level', 16); // info | news | warning
            $table->string('title_es', 80);
            $table->string('title_en', 80);
            $table->string('body_es', 400);
            $table->string('body_en', 400);
            $table->string('cta_label_es', 30)->nullable();
            $table->string('cta_label_en', 30)->nullable();
            $table->string('cta_url', 500)->nullable();
            $table->boolean('dismissible')->default(true);
            $table->string('audience', 24); // all | free | paid | upcoming_event
            $table->unsignedSmallInteger('upcoming_days')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
