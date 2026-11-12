<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->foreignId('template_id')->nullable()->constrained('invitation_templates')->nullOnDelete();
            $table->json('template_config')->nullable();
            $table->string('locale', 5)->default('es');
            $table->string('currency', 3)->default('USD');
            $table->string('timezone', 64)->default('America/New_York');
        });

        Schema::table('guests', function (Blueprint $table) {
            $table->string('locale', 5)->nullable();
        });

        $this->makeSlugsUnique();

        Schema::table('events', function (Blueprint $table) {
            $table->unique('custom_url_slug', 'events_custom_url_slug_unique');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropUnique('events_custom_url_slug_unique');
        });

        Schema::table('guests', function (Blueprint $table) {
            $table->dropColumn('locale');
        });

        Schema::table('events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('template_id');
            $table->dropColumn(['template_config', 'locale', 'currency', 'timezone']);
        });
    }

    /**
     * Legacy data could contain repeated slugs. Keep the oldest event with the
     * clean slug and suffix the id on the rest so the unique index can be created.
     */
    private function makeSlugsUnique(): void
    {
        $duplicated = DB::table('events')
            ->select('custom_url_slug')
            ->groupBy('custom_url_slug')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('custom_url_slug');

        foreach ($duplicated as $slug) {
            $ids = DB::table('events')->where('custom_url_slug', $slug)->orderBy('id')->pluck('id');

            foreach ($ids->slice(1) as $id) {
                DB::table('events')->where('id', $id)->update(['custom_url_slug' => "{$slug}-{$id}"]);
            }
        }
    }
};
