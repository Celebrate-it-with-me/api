<?php

namespace App\Http\Services\Admin\Health;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Pending work per queue, for the drivers we can read (database and redis), plus the scheduler heartbeat.
 * Whatever cannot be measured reliably is reported as `null` / `unknown`, never guessed.
 */
class QueueStatus
{
    public const HEARTBEAT_KEY = 'health:scheduler-heartbeat';

    /** A scheduler that has not beaten for this long is stale (it beats every minute). */
    private const SCHEDULER_STALE_AFTER = 180;

    /** Pending work this old suggests nobody is processing the queue. */
    private const STALLED_AFTER = 300;

    public function report(): array
    {
        $connection = (string) config('queue.default');
        $driver = (string) config("queue.connections.{$connection}.driver", $connection);

        $base = ['connection' => $connection, 'driver' => $driver, 'measurable' => false, 'error' => null,
            'queues' => [], 'pendingTotal' => null, 'oldestPendingSeconds' => null, 'stalledSuspected' => null];

        try {
            $data = match ($driver) {
                'database' => $this->database($connection),
                'redis' => $this->redis($connection),
                default => null,
            };
        } catch (Throwable) {
            $data = null;
            $base['error'] = 'No se pudo leer el estado de las colas.';
        }

        if ($data !== null) {
            $base = array_merge($base, $data, ['measurable' => true]);
        }

        return $base;
    }

    public function scheduler(): array
    {
        $last = null;
        try {
            $value = Cache::get(self::HEARTBEAT_KEY);
            $last = $value ? Carbon::parse($value) : null;
        } catch (Throwable) {
        }

        if (! $last) {
            return ['status' => 'unknown', 'lastBeatAt' => null, 'secondsAgo' => null];
        }

        $ago = (int) abs(now()->diffInSeconds($last));

        return ['status' => $ago <= self::SCHEDULER_STALE_AFTER ? 'ok' : 'stale', 'lastBeatAt' => $last->toIso8601String(), 'secondsAgo' => $ago];
    }

    private function database(string $connection): array
    {
        $table = (string) config("queue.connections.{$connection}.table", 'jobs');
        $rows = DB::table($table)
            ->select('queue', DB::raw('count(*) as pending'), DB::raw('min(available_at) as oldest'))
            ->whereNull('reserved_at')
            ->groupBy('queue')
            ->get();
        $reserved = DB::table($table)->whereNotNull('reserved_at')->select('queue', DB::raw('count(*) as total'))->groupBy('queue')->pluck('total', 'queue');

        $names = collect([(string) config("queue.connections.{$connection}.queue", 'default')])
            ->merge($rows->pluck('queue'))->merge($reserved->keys())->unique()->values();

        $queues = $names->map(function (string $name) use ($rows, $reserved) {
            $row = $rows->firstWhere('queue', $name);

            return ['name' => $name, 'pending' => (int) ($row->pending ?? 0), 'delayed' => null, 'reserved' => (int) ($reserved[$name] ?? 0)];
        })->all();

        $oldest = $rows->min('oldest');
        $age = $oldest ? max(0, now()->timestamp - (int) $oldest) : null;

        return ['queues' => $queues, 'pendingTotal' => array_sum(array_column($queues, 'pending')),
            'oldestPendingSeconds' => $age, 'stalledSuspected' => $age !== null && $age > self::STALLED_AFTER];
    }

    private function redis(string $connection): array
    {
        $redis = Redis::connection((string) config("queue.connections.{$connection}.connection", 'default'));

        $names = collect([(string) config("queue.connections.{$connection}.queue", 'default')])
            ->merge(DB::table((string) config('queue.failed.table', 'failed_jobs'))->distinct()->limit(20)->pluck('queue'))
            ->unique()->values();

        $queues = $names->map(fn (string $name) => [
            'name' => $name,
            'pending' => (int) $redis->llen("queues:{$name}"),
            'delayed' => (int) $redis->zcard("queues:{$name}:delayed"),
            'reserved' => (int) $redis->zcard("queues:{$name}:reserved"),
        ])->all();

        // Redis keeps no enqueue time we can read cheaply: the age of pending work stays unknown.
        return ['queues' => $queues, 'pendingTotal' => array_sum(array_column($queues, 'pending')),
            'oldestPendingSeconds' => null, 'stalledSuspected' => null];
    }
}
