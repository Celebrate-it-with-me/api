<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('theme_base_assets', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('theme_id')
                ->constrained('themes')
                ->cascadeOnDelete();
            
            $table->string('section');       // hero, rsvp, location, etc.
            $table->string('asset_type');    // background, image, video, thumbnail
            
            $table->string('provider')->default('upload'); // upload|url|unsplash|etc
            $table->string('disk')->nullable();
            
            $table->string('file_path');
            $table->string('file_name')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('mime_type')->nullable();
            
            $table->json('meta')->nullable();
            
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            
            $table->timestamps();
            
            $table->index(['theme_id', 'section', 'asset_type', 'is_active']);
        });
    }
    
    public function down(): void
    {
        Schema::dropIfExists('theme_base_assets');
    }
};
