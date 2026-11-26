<?php

namespace App\Http\Services\Account;

use App\Models\EventPurchase;
use App\Models\Events;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Removes an event for good: guests and everything that hangs from the event (rows and uploaded files).
 *
 * Purchases and invoices are accounting records and are never deleted (their foreign keys are `restrict`): an event
 * that has any keeps its row, scrubbed of everything personal and soft deleted. An event without them is deleted.
 * Idempotent: purging an event that is already gone does nothing.
 */
class EventPurger
{
    /** Tables with an `event_id` column that must survive the purge. */
    private const KEEP = ['events', 'event_purchases', 'invoices', 'admin_audit_log', 'support_requests'];

    /** @var list<string>|null */
    private static ?array $eventTables = null;

    /** @return bool true when the row of the event was kept (accounting records), false when it was deleted */
    public function purge(Events $event): bool
    {
        $id = $event->id;
        $files = $this->files($id);

        $kept = DB::transaction(function () use ($id, $event) {
            foreach ($this->eventTables() as $table) {
                DB::table($table)->where('event_id', $id)->delete();
            }

            $hasRecords = EventPurchase::query()->where('event_id', $id)->exists() || Invoice::query()->where('event_id', $id)->exists();

            if (! $hasRecords) {
                $event->forceDelete();

                return false;
            }

            // The row stays for the invoices that point to it: nothing of the celebration remains in it.
            $event->forceFill([
                'event_name' => 'Evento eliminado',
                'event_description' => null,
                'custom_url_slug' => "deleted-{$id}",
                'template_config' => null,
                'status' => 'archived',
                'archived_at' => $event->archived_at ?? now(),
                'purge_after' => null,
            ])->save();
            $event->delete();

            return true;
        });

        // Files go once the rows are really gone (also when this runs inside the account deletion transaction).
        DB::afterCommit(fn () => $this->deleteFiles($files, $id));

        return $kept;
    }

    /** @return list<string> */
    private function eventTables(): array
    {
        return self::$eventTables ??= collect(Schema::getTables())
            ->pluck('name')
            ->reject(fn (string $table) => in_array($table, self::KEEP, true))
            ->filter(fn (string $table) => Schema::hasColumn($table, 'event_id'))
            ->values()->all();
    }

    /** @return array<string, list<string>> paths per disk, read before the rows disappear */
    private function files(int $eventId): array
    {
        $paths = fn (string $table, array $columns, ?string $via = null, ?string $viaTable = null) => DB::table($table)
            ->when($via, fn ($q) => $q->whereIn($via, DB::table($viaTable)->where('event_id', $eventId)->select('id')),
                fn ($q) => $q->where('event_id', $eventId))
            ->get($columns)
            ->flatMap(fn ($row) => array_values((array) $row))
            ->filter()->values()->all();

        return [
            'sweet-memories' => $paths('sweet_memories_images', ['image_path', 'thumbnail_path']),
            'public' => [
                ...$paths('background_music', ['song_url']),
                ...$paths('dress_code_images', ['image_path'], 'dress_code_id', 'dress_codes'),
                ...$paths('event_location_images', ['path'], 'event_location_id', 'event_locations'),
                ...$paths('menu_items', ['image_path'], 'menu_id', 'menus'),
                ...$paths('save_the_date', ['image_url']),
            ],
            'default' => $paths('theme_assets', ['file_path'], 'event_theme_id', 'event_themes'),
            // The cover photo and the image zones of the invitation live in their own folder, not in a table: the folder goes whole.
            'event-media' => [],
        ];
    }

    /** @param array<string, list<string>> $files */
    private function deleteFiles(array $files, int $eventId): void
    {
        $directories = ['sweet-memories' => ["images/event/{$eventId}"], 'public' => ["sound/background-music/{$eventId}"], 'default' => [], 'event-media' => ["images/event/{$eventId}"]];

        foreach ($files as $disk => $paths) {
            try {
                $storage = Storage::disk(match ($disk) {
                    'default' => config('filesystems.default'), 'public' => config('filesystems.media_disk'), default => $disk
                });
                $prefix = rtrim($storage->url(''), '/') . '/';

                // Some columns hold the public URL instead of the path: strip the disk's own prefix, ignore foreign URLs.
                $relative = collect($paths)->map(fn (string $p) => str_starts_with($p, $prefix) ? substr($p, strlen($prefix)) : $p)
                    ->reject(fn (string $p) => preg_match('#^https?://#i', $p) === 1)->unique()->values()->all();

                $storage->delete($relative);
                foreach ($directories[$disk] as $directory) {
                    $storage->deleteDirectory($directory);
                }
            } catch (Throwable $e) {
                // Rows are already gone; a file that cannot be removed is logged (no personal data) for the next sweep.
                Log::warning('account purge: files not removed', ['event_id' => $eventId, 'disk' => $disk, 'error' => $e->getMessage()]);
            }
        }
    }
}
