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
        Schema::create('dress_code_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dress_code_id')->constrained('dress_codes')->onDelete('cascade');
            $table->string('image_path');
            $table->string('original_name')->nullable();
            $table->integer('file_size')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('dress_code_id', 'idx_dress_code_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dress_code_images');
    }
};
