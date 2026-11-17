<?php

use App\Http\Services\AppServices\ModuleSupport;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which governed modules (comments, seating) each installed package can show, computed from its stored AST. Idempotent:
 * only rows without a value are filled, and no event data is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('template_packages', 'supported_modules')) {
            Schema::table('template_packages', function (Blueprint $table) {
                $table->json('supported_modules')->nullable()->after('report');
            });
        }

        DB::table('template_packages')->whereNull('supported_modules')->orderBy('id')->select(['id', 'ast'])->each(function ($row) {
            $ast = json_decode((string) $row->ast, true);
            DB::table('template_packages')->where('id', $row->id)->update([
                'supported_modules' => json_encode(ModuleSupport::fromAst(is_array($ast) ? $ast : [])),
            ]);
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('template_packages', 'supported_modules')) {
            Schema::table('template_packages', function (Blueprint $table) {
                $table->dropColumn('supported_modules');
            });
        }
    }
};
