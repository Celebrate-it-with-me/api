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
        Schema::create('theme_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_theme_id')
                ->constrained('event_themes')
                ->onDelete('cascade');
            $table->string('section', 50); // 'hero', 'rsvp', 'save_the_date', 'location', 'global'
            $table->enum('asset_type', [
                'background_image',
                'background_video',
                'logo',
                'favicon',
                'custom'
            ]);
            $table->string('file_path', 500);
            $table->string('file_name', 255);
            $table->integer('file_size')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->string('alt_text', 255)->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Índices
            $table->index(['event_theme_id', 'section']);
            $table->index(['event_theme_id', 'asset_type']);
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('theme_assets');
    }
};
