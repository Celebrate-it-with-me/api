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
        Schema::table('event_themes', function (Blueprint $table) {
            $table->foreignId('theme_id')
                ->nullable()
                ->after('event_id')
                ->constrained('themes')
                ->nullOnDelete();
            
            $table->unsignedInteger('theme_version')
                ->default(1)
                ->after('theme_id');
            
            $table->index(['event_id', 'is_active']);
            $table->index(['event_id', 'theme_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('event_themes', function (Blueprint $table) {
            $table->dropIndex(['event_id', 'is_active']);
            $table->dropIndex(['event_id', 'theme_id']);
            
            $table->dropConstrainedForeignId('theme_id');
            $table->dropColumn('theme_version');
        });
    }
};
