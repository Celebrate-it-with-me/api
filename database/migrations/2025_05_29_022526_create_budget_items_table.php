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
        Schema::create('budget_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('event_budget_id')->constrained('event_budgets')->onDelete('cascade');
            $table->foreignId('category_id')->nullable()->constrained('budget_categories')->nullOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            $table->decimal('estimated_cost', 12, 2)->default(0.00);
            $table->decimal('actual_cost', 12, 2)->nullable();

            $table->boolean('is_paid')->default(false);
            $table->date('due_date')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('budget_items');
    }
};
