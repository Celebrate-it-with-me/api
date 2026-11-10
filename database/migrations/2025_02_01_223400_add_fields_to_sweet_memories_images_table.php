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
        // The table is created by 2025_04_09_023039_create_sweet_memories_images_table
        // (which already includes these columns). On a fresh database this migration
        // runs first, so it must be a no-op; on existing databases it only adds
        // whatever is still missing.
        if (! Schema::hasTable('sweet_memories_images')) {
            return;
        }

        Schema::table('sweet_memories_images', function (Blueprint $table) {
            if (! Schema::hasColumn('sweet_memories_images', 'title')) {
                $table->string('title')->nullable()->after('thumbnail_name');
            }
            if (! Schema::hasColumn('sweet_memories_images', 'description')) {
                $table->text('description')->nullable()->after('title');
            }
            if (! Schema::hasColumn('sweet_memories_images', 'year')) {
                $table->string('year')->nullable()->after('description');
            }
            if (! Schema::hasColumn('sweet_memories_images', 'visible')) {
                $table->boolean('visible')->default(true)->after('year');
            }
            if (! Schema::hasColumn('sweet_memories_images', 'order')) {
                $table->integer('order')->default(0)->after('visible');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('sweet_memories_images')) {
            return;
        }

        Schema::table('sweet_memories_images', function (Blueprint $table) {
            $table->dropColumn(['title', 'description', 'year', 'visible', 'order']);
        });
    }
};
