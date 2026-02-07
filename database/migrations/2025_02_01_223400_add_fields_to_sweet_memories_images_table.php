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
        Schema::table('sweet_memories_images', function (Blueprint $table) {
            $table->string('title')->nullable()->after('thumbnail_name');
            $table->text('description')->nullable()->after('title');
            $table->string('year')->nullable()->after('description');
            $table->boolean('visible')->default(true)->after('year');
            $table->integer('order')->default(0)->after('visible');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sweet_memories_images', function (Blueprint $table) {
            $table->dropColumn(['title', 'description', 'year', 'visible', 'order']);
        });
    }
};
