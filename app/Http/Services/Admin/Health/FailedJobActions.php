<?php

namespace App\Http\Services\Admin\Health;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/** Retry / forget failed jobs through the framework's own `queue:retry` and `queue:forget`. Never reads or returns the payload. */
class FailedJobActions
{
    public const BULK_LIMIT = 50;

    private function table(): string
    {
        return (string) config('queue.failed.table', 'failed_jobs');
    }

    /** @return array{id: int, uuid: string, job: string|null, queue: string}|null */
    public function find(string $uuid): ?array
    {
        $row = DB::table($this->table())->select(['id', 'uuid', 'queue', 'payload'])->where('uuid', $uuid)->first();

        return $row ? $this->summary($row) : null;
    }

    /** @return bool false when the framework could not rebuild the job (corrupt payload); it stays in the failed list. */
    public function retry(string $uuid): bool
    {
        try {
            Artisan::call('queue:retry', ['id' => [$uuid]]);

            return DB::table($this->table())->where('uuid', $uuid)->doesntExist();
        } catch (\Throwable) {
            return false;
        }
    }

    public function forget(string $uuid): void
    {
        Artisan::call('queue:forget', ['id' => $uuid]);
    }

    /**
     * Oldest first, at most BULK_LIMIT, from one queue.
     *
     * @return list<array{id: int, uuid: string, job: string|null, queue: string}>
     */
    public function batch(string $queue): array
    {
        return DB::table($this->table())->select(['id', 'uuid', 'queue', 'payload'])
            ->where('queue', $queue)->orderBy('id')->limit(self::BULK_LIMIT)->get()
            ->map(fn ($row) => $this->summary($row))->all();
    }

    /**
     * One by one, so a single corrupt payload cannot sink the rest.
     *
     * @return list<string> the uuids that went back to the queue
     */
    public function retryMany(array $uuids): array
    {
        return array_values(array_filter($uuids, fn (string $uuid) => $this->retry($uuid)));
    }

    public function remaining(string $queue): int
    {
        return DB::table($this->table())->where('queue', $queue)->count();
    }

    private function summary(object $row): array
    {
        $payload = json_decode((string) $row->payload, true);
        $job = is_array($payload) ? ($payload['displayName'] ?? null) : null;
        $job = is_string($job) && preg_match('/^[\w\\\\@:.\- ]{1,150}$/', $job) ? $job : null;

        return ['id' => (int) $row->id, 'uuid' => (string) $row->uuid, 'job' => $job, 'queue' => (string) $row->queue];
    }
}
