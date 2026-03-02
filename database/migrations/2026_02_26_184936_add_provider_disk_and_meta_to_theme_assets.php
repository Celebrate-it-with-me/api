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
        Schema::table('theme_assets', function (Blueprint $table) {
            $table->string('provider')->default('upload')->after('asset_type'); // upload|url|places|youtube|unsplash|pexels
            $table->string('disk')->nullable()->after('provider'); // optional storage disk name
            $table->json('meta')->nullable()->after('alt_text'); // any extra provider info
            
            // Indexes for filtering on preview
            $table->index(['event_theme_id', 'section', 'asset_type', 'is_active']);
            $table->index(['event_theme_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('theme_assets', function (Blueprint $table) {
            $table->dropIndex(['event_theme_id', 'section', 'asset_type', 'is_active']);
            $table->dropIndex(['event_theme_id', 'is_active']);
            
            $table->dropColumn(['provider', 'disk', 'meta']);
        });
    }
};
