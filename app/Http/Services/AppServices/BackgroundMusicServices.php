<?php

namespace App\Http\Services\AppServices;

use App\Models\BackgroundMusic;
use App\Models\Events;
use App\Support\InvitationCache;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The background song of the invitation: one per event. Uploading again replaces it (the old file leaves the disk),
 * deleting removes the row and the file.
 */
class BackgroundMusicServices
{
    /** Extension of the stored file => the type the player is told. */
    private const MIME_BY_EXTENSION = [
        'mp3' => 'audio/mpeg',
        'm4a' => 'audio/mp4',
        'mp4' => 'audio/mp4', // files stored before the extension was kept from the (validated) name
        'aac' => 'audio/aac',
        'ogg' => 'audio/ogg',
    ];

    /** Content types accepted by content sniffing, and the extensions a client may name the file with. */
    public const ALLOWED_MIMETYPES = ['audio/mpeg', 'audio/mp3', 'audio/mp4', 'audio/x-m4a', 'audio/aac', 'audio/ogg', 'application/ogg', 'video/mp4'];

    /** Android records m4a with an mp4 box (ftypmp42/isom) that sniffs as video/mp4: valid only under these extensions. */
    public const VIDEO_MP4_EXTENSIONS = ['m4a', 'aac'];

    public const ALLOWED_EXTENSIONS = ['mp3', 'm4a', 'aac', 'ogg'];

    /**
     * Save the song of the event, replacing the one it had.
     *
     * @param  array{title?: ?string, iconSize?: ?string, iconPosition?: ?string, iconColor?: ?string, autoplay?: mixed}  $data
     * @return array{0: BackgroundMusic, 1: bool} the song and whether it was created (false = replaced)
     */
    public function save(Events $event, UploadedFile $file, array $data = []): array
    {
        // The extension is the one the request validated against the allowed list, never the one guessed from
        // the content (an Android m4a sniffs as video/mp4 and would be stored as .mp4).
        $extension = strtolower($file->getClientOriginalExtension());
        $extension = in_array($extension, self::ALLOWED_EXTENSIONS, true) ? $extension : 'mp3';
        $path = $file->storeAs("sound/background-music/{$event->id}", Str::random(40) . '.' . $extension, config('filesystems.media_disk'));

        $existing = BackgroundMusic::query()->where('event_id', $event->id)->orderByDesc('id')->get();
        $music = $existing->first();
        $stale = $existing->skip(1)->concat($music ? [$music] : []);
        $oldFiles = $existing->pluck('song_url')->filter()->all();

        $attributes = ['song_url' => $path];
        // A replacement without a `title` key keeps the title it had; a key that comes empty clears it.
        if (array_key_exists('title', $data) || ! $music) {
            $attributes['title'] = $this->cleanTitle($data['title'] ?? null);
        }
        foreach (['iconSize' => 'icon_size', 'iconPosition' => 'icon_position', 'iconColor' => 'icon_color'] as $input => $column) {
            if (array_key_exists($input, $data)) {
                $attributes[$column] = $data[$input];
            }
        }

        if ($music) {
            $music->forceFill($attributes)->save();
            BackgroundMusic::query()->whereIn('id', $existing->skip(1)->pluck('id'))->delete();
            InvitationCache::flushEvent($event->id);
        } else {
            $music = BackgroundMusic::query()->create($attributes + ['event_id' => $event->id]);
        }

        // The row points at the new file before the old ones go: a failure in between leaves a stray file, never a dead link.
        Storage::disk(config('filesystems.media_disk'))->delete($oldFiles);

        return [$music->refresh(), $existing->isEmpty()];
    }

    /** Remove the song (row and file). Returns false when the event has none. */
    public function delete(Events $event): bool
    {
        $rows = BackgroundMusic::query()->where('event_id', $event->id)->get();

        if ($rows->isEmpty()) {
            return false;
        }

        BackgroundMusic::query()->whereIn('id', $rows->pluck('id'))->delete();
        InvitationCache::flushEvent($event->id);
        Storage::disk(config('filesystems.media_disk'))->delete($rows->pluck('song_url')->filter()->all());

        return true;
    }

    /**
     * Whether an mp4-family file carries a video track (a `hdlr` box whose handler type is `vide`). The file is capped
     * at 10 MB, so reading it whole to find the box is cheap; `moov` may sit at the end, hence no partial read.
     */
    public static function hasVideoTrack(UploadedFile $file): bool
    {
        $bytes = @file_get_contents($file->getRealPath());

        return is_string($bytes) && preg_match('/hdlr.{8}vide/s', $bytes) === 1;
    }

    public static function mimeTypeOf(?string $path): ?string
    {
        return $path ? (self::MIME_BY_EXTENSION[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? 'audio/mpeg') : null;
    }

    public static function sizeOf(?string $path): ?int
    {
        try {
            return $path && Storage::disk(config('filesystems.media_disk'))->exists($path) ? Storage::disk(config('filesystems.media_disk'))->size($path) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function cleanTitle(?string $title): ?string
    {
        $title = $title === null ? '' : trim($title);

        return $title === '' ? null : $title;
    }
}
