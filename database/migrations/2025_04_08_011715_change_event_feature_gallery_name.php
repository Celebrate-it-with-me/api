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
        Schema::table('event_features', function (Blueprint $table) {
            $table->renameColumn('gallery', 'sweet_memories');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('event_features', function (Blueprint $table) {
            $table->renameColumn('sweet_memories', 'gallery');
        });
    }
};
