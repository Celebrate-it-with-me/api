<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The invitation gains the `suggested_music` section (docs/plans/invitation-premium-modules-contract.md, section 5). A
 * published version never changes, so for every template that lacks it this publishes its next version with the
 * section added before `comments`/`closing`, ON: new events (drafts, which follow the current version) start with it. The
 * events already fixed to the previous version move to the new one with the section OFF in their own template_config:
 * their invitations were already sent, and the organizer turns it on from the editor.
 *
 * Versions managed by a template package are left alone: the package decides which sections it draws.
 * "If missing": a template that already has the type keeps it as it is; running it twice changes nothing.
 */
return new class extends Migration
{
    private const TYPE = 'suggested_music';

    public function up(): void
    {
        $now = now();

        foreach (DB::table('invitation_templates')->get() as $template) {
            $current = $template->current_version_id
                ? DB::table('invitation_template_versions')->find($template->current_version_id)
                : null;

            if ($current !== null && $current->package_id !== null) {
                continue;
            }

            $sections = json_decode($current->sections ?? $template->default_sections, true) ?: [];

            if (in_array(self::TYPE, array_column($sections, 'type'), true)) {
                continue;
            }

            usort($sections, fn ($a, $b) => ($a['order'] ?? 99) <=> ($b['order'] ?? 99));

            $extra = [
                'type' => self::TYPE,
                'enabled' => true,
                'title' => ['es' => 'Tu música', 'en' => 'Your music'],
                'text' => ['es' => 'Sugiere las canciones que no pueden faltar.', 'en' => 'Suggest the songs we can’t miss.'],
                'settings' => (object) [],
            ];

            $before = collect($sections)->pluck('type')->intersect(['comments', 'closing'])->first();
            $next = [];
            $placed = false;
            foreach ($sections as $section) {
                if (! $placed && $section['type'] === $before) {
                    $next[] = $extra;
                    $placed = true;
                }
                $next[] = $section;
            }
            if (! $placed) {
                $next[] = $extra;
            }
            foreach ($next as $i => &$section) {
                $section['order'] = $i + 1;
            }
            unset($section);

            $json = json_encode($next, JSON_UNESCAPED_UNICODE);
            $themeJson = $current->theme ?? $template->theme_defaults;
            $version = (int) DB::table('invitation_template_versions')->where('template_id', $template->id)->max('version') + 1;

            // Only one published version per template at a time: the previous one is archived first.
            if ($current && $current->status === 'published') {
                DB::table('invitation_template_versions')->where('id', $current->id)->update(['status' => 'archived', 'archived_at' => $now]);
            }

            $versionId = DB::table('invitation_template_versions')->insertGetId([
                'template_id' => $template->id,
                'version' => $version,
                'status' => 'published',
                'sections' => $json,
                'theme' => $themeJson,
                'schema_version' => $current->schema_version ?? 1,
                'notes' => 'Adds the suggested music section.',
                'published_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($current && $current->status === 'published') {
                $moved = DB::table('events')->where('template_version_id', $current->id)->pluck('id')->all();
                DB::table('events')->where('template_version_id', $current->id)->update(['template_version_id' => $versionId]);
                $this->switchOff($moved);
            }

            DB::table('invitation_templates')->where('id', $template->id)->update([
                'default_sections' => $json,
                'current_version_id' => $versionId,
                'updated_at' => $now,
            ]);
        }
    }

    /** @param array<int, int> $eventIds */
    private function switchOff(array $eventIds): void
    {
        foreach (array_chunk($eventIds, 500) as $chunk) {
            foreach (DB::table('events')->whereIn('id', $chunk)->get(['id', 'template_config']) as $event) {
                $config = json_decode($event->template_config ?? 'null', true) ?: [];
                $sections = $config['sections'] ?? [];
                $found = false;

                foreach ($sections as &$section) {
                    if (($section['type'] ?? null) === self::TYPE) {
                        $section['enabled'] = false;
                        $found = true;
                    }
                }
                unset($section);
                if (! $found) {
                    $sections[] = ['type' => self::TYPE, 'enabled' => false];
                }
                $config['sections'] = array_values($sections);

                DB::table('events')->where('id', $event->id)->update(['template_config' => json_encode($config, JSON_UNESCAPED_UNICODE)]);
            }
        }
    }

    public function down(): void {}
};
