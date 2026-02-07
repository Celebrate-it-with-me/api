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
        Schema::create('menu_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_id')
                ->constrained('menus')
                ->onDelete('cascade');
            $table->string('name'); // Name of the menu item
            $table->string('diet_type')->nullable(); // Diet type (e.g., vegetarian, vegan, gluten-free)
            $table->string('image_path')->nullable();
            $table->text('notes')->nullable(); // Description of the menu item
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('menu_items');
    }
};
