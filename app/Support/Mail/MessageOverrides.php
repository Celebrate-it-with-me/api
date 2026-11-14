<?php

namespace App\Support\Mail;

use App\Models\MessageOverride;
use Closure;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Reads the texts an admin edited (table message_overrides) for the mail translator. One cached map for the whole table,
 * dropped on every write. Any failure while reading (database, cache) answers "no override": a mail is always sent with
 * the text of the lang file at worst.
 */
class MessageOverrides
{
    public const CACHE_KEY = 'message_overrides.v1';

    /** @var array<string, string>|null unsaved texts shown by a preview, keyed "locale|item" */
    private ?array $preview = null;

    private bool $reading = false;

    public function lookup(string $locale, string $item): ?string
    {
        if ($this->reading) {
            return null;
        }

        $this->reading = true;
        try {
            $slot = $locale . '|' . $item;
            if ($this->preview !== null && isset($this->preview[$slot])) {
                return $this->preview[$slot];
            }

            return $this->all()[$slot] ?? null;
        } catch (Throwable $e) {
            report($e);

            return null;
        } finally {
            $this->reading = false;
        }
    }

    /** @return array<string, string> */
    public function all(): array
    {
        try {
            return Cache::rememberForever(self::CACHE_KEY, fn () => $this->load());
        } catch (Throwable) {
            return $this->load();
        }
    }

    public function forget(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Runs $callback with extra, unsaved texts on top of the saved ones (the editor's preview).
     *
     * @param  array<string, string>  $fields  field => text
     */
    public function withPreview(string $key, string $locale, array $fields, Closure $callback): mixed
    {
        $previous = $this->preview;
        $this->preview = $previous ?? [];
        foreach ($fields as $field => $text) {
            $this->preview[$locale . '|' . MessageCatalog::item($key, $field)] = $text;
        }

        try {
            return $callback();
        } finally {
            $this->preview = $previous;
        }
    }

    /** @return array<string, string> */
    private function load(): array
    {
        $map = [];
        foreach (MessageOverride::query()->get(['message_key', 'locale', 'field', 'value']) as $row) {
            if (MessageCatalog::isEditable($row->message_key) && in_array($row->field, MessageCatalog::fields($row->message_key), true)) {
                $map[$row->locale . '|' . MessageCatalog::item($row->message_key, $row->field)] = $row->value;
            }
        }

        return $map;
    }
}
