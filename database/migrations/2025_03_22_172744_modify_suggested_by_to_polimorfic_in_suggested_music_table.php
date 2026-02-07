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
        Schema::table('suggested_music', function (Blueprint $table) {
            $table->dropColumn('suggested_by');
            $table->string('suggested_by_entity');
            $table->unsignedBigInteger('suggested_by_id');
            $table->index(['suggested_by_entity', 'suggested_by_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('suggested_music', function (Blueprint $table) {
            $table->dropIndex(['suggested_by_entity', 'suggested_by_id']);
            $table->dropColumn(['suggested_by_entity', 'suggested_by_id']);
            $table->string('suggested_by');
        });
    }
};
