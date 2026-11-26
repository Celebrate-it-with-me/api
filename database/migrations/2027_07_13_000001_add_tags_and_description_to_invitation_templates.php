<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The template picker shows a short description and style tags per template. Both are plain data of the row (the
 * package manifest still wins for `description` when it has one): `description` is `{es, en}`, `tags` a list of words.
 * Nullable on purpose: nothing is invented here, the texts are loaded later with `invitation:template-meta`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitation_templates', function (Blueprint $table) {
            $table->json('description')->nullable()->after('preview_image');
            $table->json('tags')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('invitation_templates', function (Blueprint $table) {
            $table->dropColumn(['description', 'tags']);
        });
    }
};
