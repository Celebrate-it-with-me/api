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
        Schema::create('budget_item_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_item_id')->constrained('budget_items')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            
            $table->unsignedTinyInteger('threshold_days');
            $table->timestamp('sent_at');
            $table->timestamps();
            
            $table->unique(['budget_item_id', 'user_id', 'threshold_days'], 'budget_item_reminders_unique');
            $table->index('sent_at');
            $table->index('budget_item_id');
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('budget_item_reminders');
    }
};
