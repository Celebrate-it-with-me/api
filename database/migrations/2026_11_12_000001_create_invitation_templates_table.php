<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitation_templates', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            // Null means the template works for any event type.
            $table->foreignId('event_type_id')->nullable()->constrained('event_types')->nullOnDelete();
            $table->string('tier', 16)->default('free');
            $table->string('preview_image')->nullable();
            $table->json('default_sections');
            $table->json('theme_defaults');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['event_type_id', 'tier', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitation_templates');
    }
};
