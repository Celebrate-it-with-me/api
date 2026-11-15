<?php

namespace App\Http\Services\AppServices;

use App\Models\BackgroundMusic;
use App\Models\Events;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The background song of the invitation: one per event. Uploading again replaces it (the old file leaves the disk),
 * deleting removes the row and the file.
 */
class BackgroundMusicServices
{
    private const DISK = 'public';

    /** Extension of the stored file => the type the player is told. */
    private const MIME_BY_EXTENSION = [
        'mp3' => 'audio/mpeg',
        'm4a' => 'audio/mp4',
        'aac' => 'audio/aac',
        'ogg' => 'audio/ogg',
    ];

    /** Content types accepted by content sniffing, and the extensions a client may name the file with. */
    public const ALLOWED_MIMETYPES = ['audio/mpeg', 'audio/mp3', 'audio/mp4', 'audio/x-m4a', 'audio/aac', 'audio/ogg', 'application/ogg'];

    public const ALLOWED_EXTENSIONS = ['mp3', 'm4a', 'aac', 'ogg'];

    /**
     * Save the song of the event, replacing the one it had.
     *
     * @param  array{title?: ?string, iconSize?: ?string, iconPosition?: ?string, iconColor?: ?string, autoplay?: mixed}  $data
     * @return array{0: BackgroundMusic, 1: bool} the song and whether it was created (false = replaced)
     */
    public function save(Events $event, UploadedFile $file, array $data = []): array
    {
        $path = $file->store("sound/background-music/{$event->id}", self::DISK);

        $existing = BackgroundMusic::query()->where('event_id', $event->id)->orderByDesc('id')->get();
        $music = $existing->first();
        $stale = $existing->skip(1)->concat($music ? [$music] : []);
        $oldFiles = $existing->pluck('song_url')->filter()->all();

        $attributes = ['song_url' => $path, 'title' => $this->cleanTitle($data['title'] ?? null)];
        foreach (['iconSize' => 'icon_size', 'iconPosition' => 'icon_position', 'iconColor' => 'icon_color'] as $input => $column) {
            if (array_key_exists($input, $data)) {
                $attributes[$column] = $data[$input];
            }
        }

        if ($music) {
            $music->forceFill($attributes)->save();
            BackgroundMusic::query()->whereIn('id', $existing->skip(1)->pluck('id'))->delete();
        } else {
            $music = BackgroundMusic::query()->create($attributes + ['event_id' => $event->id]);
        }

        // The row points at the new file before the old ones go: a failure in between leaves a stray file, never a dead link.
        Storage::disk(self::DISK)->delete($oldFiles);

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
        Storage::disk(self::DISK)->delete($rows->pluck('song_url')->filter()->all());

        return true;
    }

    public static function mimeTypeOf(?string $path): ?string
    {
        return $path ? (self::MIME_BY_EXTENSION[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null) : null;
    }

    public static function sizeOf(?string $path): ?int
    {
        try {
            return $path && Storage::disk(self::DISK)->exists($path) ? Storage::disk(self::DISK)->size($path) : null;
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
