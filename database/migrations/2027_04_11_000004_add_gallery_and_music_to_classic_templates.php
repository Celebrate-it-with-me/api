<?php

use Database\Seeders\InvitationTemplateSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Classic templates start showing the `gallery` and `music` sections (each one appears only when the event has
 * photos / a song and its plan includes the module). A published version never changes, so this publishes the next
 * version of each Classic template and moves the events that were on the previous one to it: the only difference
 * they get is those two sections. Templates that already have both sections, or that the admin renamed, are skipped.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach (DB::table('invitation_templates')->whereIn('slug', ['classic-quinceanera', 'classic-wedding'])->get() as $template) {
            $current = $template->current_version_id
                ? DB::table('invitation_template_versions')->find($template->current_version_id)
                : null;

            $sections = json_decode($current->sections ?? $template->default_sections, true) ?: [];
            $types = array_column($sections, 'type');

            if (in_array('gallery', $types, true) && in_array('music', $types, true)) {
                continue;
            }

            $wedding = $template->slug === 'classic-wedding';
            $extra = [
                'gallery' => [
                    'type' => 'gallery',
                    'enabled' => true,
                    'title' => $wedding ? ['es' => 'Nuestros recuerdos', 'en' => 'Our memories'] : ['es' => 'Mis recuerdos', 'en' => 'My memories'],
                    'text' => ['es' => null, 'en' => null],
                    'settings' => InvitationTemplateSeeder::defaultSettings('gallery'),
                ],
                'music' => [
                    'type' => 'music',
                    'enabled' => true,
                    'title' => ['es' => null, 'en' => null],
                    'text' => ['es' => null, 'en' => null],
                    'settings' => InvitationTemplateSeeder::defaultSettings('music'),
                ],
            ];

            usort($sections, fn ($a, $b) => ($a['order'] ?? 99) <=> ($b['order'] ?? 99));

            $next = [];
            foreach ($sections as $section) {
                if ($section['type'] === 'rsvp' && ! in_array('gallery', $types, true)) {
                    $next[] = $extra['gallery'];
                }
                $next[] = $section;
            }
            if (! in_array('gallery', $types, true) && ! in_array('gallery', array_column($next, 'type'), true)) {
                $next[] = $extra['gallery'];
            }
            if (! in_array('music', $types, true)) {
                $next[] = $extra['music'];
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
                'notes' => 'Adds the gallery and music sections.',
                'published_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($current && $current->status === 'published') {
                $moved = DB::table('events')->where('template_version_id', $current->id)->pluck('id')->all();
                DB::table('events')->where('template_version_id', $current->id)->update(['template_version_id' => $versionId]);
                $this->keepExistingContentPrivate($moved);
            }

            DB::table('invitation_templates')->where('id', $template->id)->update([
                'default_sections' => $json,
                'current_version_id' => $versionId,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Photos uploaded and a song saved before the invitation could show them were never published by the
     * organizer. For the events that already have them, the new sections start switched off in their own
     * template_config (the organizer turns them on); events with nothing keep the template default.
     *
     * @param  array<int, int>  $eventIds
     */
    private function keepExistingContentPrivate(array $eventIds): void
    {
        foreach (array_chunk($eventIds, 500) as $chunk) {
            $withPhotos = DB::table('sweet_memories_images')->whereIn('event_id', $chunk)->where('visible', true)->whereNull('hidden_at')
                ->distinct()->pluck('event_id')->all();
            $withSong = DB::table('background_music')->whereIn('event_id', $chunk)->distinct()->pluck('event_id')->all();

            foreach (['gallery' => $withPhotos, 'music' => $withSong] as $type => $ids) {
                foreach ($ids as $eventId) {
                    $event = DB::table('events')->find($eventId);
                    $config = json_decode($event->template_config ?? 'null', true) ?: [];
                    $sections = $config['sections'] ?? [];
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
                    $config['sections'] = array_values($sections);

                    DB::table('events')->where('id', $eventId)->update(['template_config' => json_encode($config, JSON_UNESCAPED_UNICODE)]);
                }
            }
        }
    }

    public function down(): void {}
};
