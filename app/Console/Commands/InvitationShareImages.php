<?php

namespace App\Console\Commands;

use App\Http\Services\AppServices\Invitation\CoverPhoto;
use App\Models\Events;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Builds the 1200x630 JPEG that link previews show (WhatsApp, Facebook) for the cover photos uploaded before that
 * image existed. Idempotent: a photo that already has one is skipped (`--force` rebuilds it); `--dry-run` only lists.
 */
class InvitationShareImages extends Command
{
    protected $signature = 'invitation:share-images {--dry-run : Only list what would be made} {--force : Rebuild the ones that already exist}';

    protected $description = 'Create the link-preview image (1200x630 JPEG) of the cover photos already uploaded';

    public function handle(CoverPhoto $covers): int
    {
        $dry = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $disk = Storage::disk(CoverPhoto::DISK);
        $made = $skipped = $failed = 0;

        Events::query()->whereNotNull('template_config')->orderBy('id')->each(function (Events $event) use ($covers, $dry, $force, $disk, &$made, &$skipped, &$failed) {
            $focus = null;
            $value = null;
            foreach (($event->template_config['sections'] ?? []) as $section) {
                if (($section['type'] ?? null) === 'cover' && is_string($section['settings']['image'] ?? null)) {
                    $value = $section['settings']['image'];
                    $focus = is_string($section['settings']['focus'] ?? null) ? $section['settings']['focus'] : null;
                }
            }
            $path = is_string($value) ? CoverPhoto::pathFor($value, $event->id) : null;
            if ($path === null) {
                return;
            }
            if (! $force && $disk->exists(CoverPhoto::sharePath($path))) {
                $skipped++;

                return;
            }
            if ($dry) {
                $this->line("event {$event->id}: would make " . CoverPhoto::sharePath($path));
                $made++;

                return;
            }
            if ($covers->makeShare($path, $focus, $force)) {
                $this->line("event {$event->id}: " . CoverPhoto::sharePath($path));
                $made++;
            } else {
                $this->warn("event {$event->id}: could not make the image from {$path}");
                $failed++;
            }
        });

        $this->info(($dry ? 'Would make' : 'Made') . " {$made}, already there {$skipped}, failed {$failed}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
