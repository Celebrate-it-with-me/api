<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The invitation gains the `comments` and `tables` sections (docs/plans/invitation-premium-modules-contract.md). A
 * published version never changes, so for every template that lacks them this publishes its next version with both
 * sections added before `closing`, ON: new events (drafts, which follow the current version) start with them. The
 * events already fixed to the previous version move to the new one with both sections switched OFF in their own
 * template_config: their invitations were already sent, and the organizer turns them on from the editor.
 *
 * Versions managed by a template package are left alone: the package decides which sections it draws.
 * "If missing": a template that already has a type keeps it as it is; running it twice changes nothing.
 */
return new class extends Migration
{
    private const NEW_TYPES = ['tables', 'comments'];

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
            $types = array_column($sections, 'type');
            $missing = array_values(array_diff(self::NEW_TYPES, $types));

            if ($missing === []) {
                continue;
            }

            $wedding = str_contains($template->slug, 'wedding');
            $extra = [
                'tables' => $this->section('tables', ['es' => 'Tu mesa', 'en' => 'Your table']),
                'comments' => $this->section('comments', ['es' => $wedding ? 'Déjanos un mensaje' : 'Deja un mensaje', 'en' => 'Leave a message']),
            ];

            usort($sections, fn ($a, $b) => ($a['order'] ?? 99) <=> ($b['order'] ?? 99));

            $next = [];
            $placed = false;
            foreach ($sections as $section) {
                if ($section['type'] === 'closing' && ! $placed) {
                    foreach ($missing as $type) {
                        $next[] = $extra[$type];
                    }
                    $placed = true;
                }
                $next[] = $section;
            }
            if (! $placed) {
                foreach ($missing as $type) {
                    $next[] = $extra[$type];
                }
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
                'notes' => 'Adds the comments and tables sections.',
                'published_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($current && $current->status === 'published') {
                $moved = DB::table('events')->where('template_version_id', $current->id)->pluck('id')->all();
                DB::table('events')->where('template_version_id', $current->id)->update(['template_version_id' => $versionId]);
                $this->switchOff($moved, $missing);
            }

            DB::table('invitation_templates')->where('id', $template->id)->update([
                'default_sections' => $json,
                'current_version_id' => $versionId,
                'updated_at' => $now,
            ]);
        }
    }

    /** @param array<string, string> $title @return array<string, mixed> */
    private function section(string $type, array $title): array
    {
        return [
            'type' => $type,
            'enabled' => true,
            'title' => $title,
            'text' => ['es' => null, 'en' => null],
            'settings' => (object) [],
        ];
    }

    /**
     * @param  array<int, int>  $eventIds
     * @param  list<string>  $types
     */
    private function switchOff(array $eventIds, array $types): void
    {
        foreach (array_chunk($eventIds, 500) as $chunk) {
            foreach (DB::table('events')->whereIn('id', $chunk)->get(['id', 'template_config']) as $event) {
                $config = json_decode($event->template_config ?? 'null', true) ?: [];
                $sections = $config['sections'] ?? [];

                foreach ($types as $type) {
                    $found = false;
                    foreach ($sections as &$section) {
                        if (($section['type'] ?? null) === $type) {
                            $section['enabled'] = false;
                            $found = true;
                        }
                    }
                    unset($section);
                    if (! $found) {
                        $sections[] = ['type' => $type, 'enabled' => false];
                    }
                }
                $config['sections'] = array_values($sections);

                DB::table('events')->where('id', $event->id)->update(['template_config' => json_encode($config, JSON_UNESCAPED_UNICODE)]);
            }
        }
    }

    public function down(): void {}
};
