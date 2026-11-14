<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitation_templates', function (Blueprint $table) {
            $table->foreignId('current_version_id')->nullable()->constrained('invitation_template_versions')->nullOnDelete();
        });

        Schema::table('events', function (Blueprint $table) {
            $table->foreignId('template_version_id')->nullable()->constrained('invitation_template_versions')->nullOnDelete();
            $table->index('template_version_id');
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('template_version_id');
        });

        Schema::table('invitation_templates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_version_id');
        });
    }

    /** Version 1 (published) per template, copied from the old columns; already-published events keep it forever. */
    private function backfill(): void
    {
        $now = now();
        $versionByTemplate = [];

        foreach (DB::table('invitation_templates')->orderBy('id')->get() as $template) {
            $versionId = DB::table('invitation_template_versions')->insertGetId([
                'template_id' => $template->id,
                'version' => 1,
                'status' => 'published',
                'sections' => $template->default_sections,
                'theme' => $template->theme_defaults,
                'schema_version' => 1,
                'notes' => null,
                'published_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $versionByTemplate[$template->id] = $versionId;
            DB::table('invitation_templates')->where('id', $template->id)->update(['current_version_id' => $versionId]);
        }

        if (! $versionByTemplate) {
            return;
        }

        // Same fallback the public invitation uses: the event's template if active, else the free one of its type, else any free one.
        $templates = DB::table('invitation_templates')->where('is_active', true)->where('tier', 'free')->orderBy('id')->get();
        $fallback = fn (?int $typeId) => $templates->firstWhere('event_type_id', $typeId)?->id
            ?? $templates->firstWhere('event_type_id', null)?->id
            ?? $templates->first()?->id;

        $active = DB::table('invitation_templates')->where('is_active', true)->pluck('id')->all();

        DB::table('events')->where('status', 'published')->orderBy('id')
            ->select(['id', 'event_type_id', 'template_id'])
            ->chunkById(500, function ($events) use ($versionByTemplate, $fallback, $active) {
                foreach ($events as $event) {
                    $templateId = $event->template_id && in_array($event->template_id, $active, true)
                        ? $event->template_id
                        : $fallback($event->event_type_id);

                    if ($templateId && isset($versionByTemplate[$templateId])) {
                        DB::table('events')->where('id', $event->id)->update(['template_version_id' => $versionByTemplate[$templateId]]);
                    }
                }
            });
    }
};
