<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dress code, second version:
 * - the type list is the one for quince and weddings (cocktail and smart casual are new; `casual` becomes
 *   `smart-casual`); the column was an enum, now a string checked against that list;
 * - the reserved colors are optional and there is a second, optional palette of suggested colors;
 * - an image is either an upload or a pick from the app's photo bank (`source` and `bank_key`).
 */
return new class extends Migration
{
    private const TYPES = ['black-tie', 'formal', 'semi-formal', 'cocktail', 'smart-casual', 'thematic'];

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE dress_codes DROP CONSTRAINT IF EXISTS dress_codes_dress_code_type_check');
        }

        Schema::table('dress_codes', function (Blueprint $table) {
            $table->string('dress_code_type', 20)->change();
        });
        Schema::table('dress_codes', function (Blueprint $table) {
            $table->json('reserved_colors')->nullable()->change();
            $table->json('suggested_colors')->nullable()->after('reserved_colors');
        });

        DB::table('dress_codes')->where('dress_code_type', 'casual')->update(['dress_code_type' => 'smart-casual']);

        if (DB::getDriverName() === 'pgsql') {
            $list = "'" . implode("', '", self::TYPES) . "'";
            DB::statement("ALTER TABLE dress_codes ADD CONSTRAINT dress_codes_dress_code_type_check CHECK (dress_code_type IN ($list))");
        }

        Schema::table('dress_code_images', function (Blueprint $table) {
            $table->string('image_path')->nullable()->change();
        });
        Schema::table('dress_code_images', function (Blueprint $table) {
            $table->string('source', 10)->default('upload')->after('dress_code_id');
            $table->string('bank_key', 80)->nullable()->after('image_path');
        });
    }

    public function down(): void
    {
        DB::table('dress_code_images')->where('source', 'bank')->delete();

        Schema::table('dress_code_images', function (Blueprint $table) {
            $table->dropColumn(['source', 'bank_key']);
        });
        Schema::table('dress_code_images', function (Blueprint $table) {
            $table->string('image_path')->nullable(false)->change();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE dress_codes DROP CONSTRAINT IF EXISTS dress_codes_dress_code_type_check');
        }

        DB::table('dress_codes')->where('dress_code_type', 'smart-casual')->update(['dress_code_type' => 'casual']);
        DB::table('dress_codes')->where('dress_code_type', 'cocktail')->update(['dress_code_type' => 'formal']);
        DB::table('dress_codes')->whereNull('reserved_colors')->update(['reserved_colors' => '[]']);

        Schema::table('dress_codes', function (Blueprint $table) {
            $table->dropColumn('suggested_colors');
        });
        Schema::table('dress_codes', function (Blueprint $table) {
            $table->json('reserved_colors')->nullable(false)->change();
            $table->string('dress_code_type', 20)->change();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE dress_codes ADD CONSTRAINT dress_codes_dress_code_type_check CHECK (dress_code_type IN ('formal', 'semi-formal', 'casual', 'thematic', 'black-tie'))");
        }
    }
};
