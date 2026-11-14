<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_overrides', function (Blueprint $table) {
            $table->id();
            $table->string('message_key', 80);
            $table->string('locale', 2);
            $table->string('field', 40);
            $table->text('value');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['message_key', 'locale', 'field']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_overrides');
    }
};
