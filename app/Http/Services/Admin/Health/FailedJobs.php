<?php

namespace App\Http\Services\Admin\Health;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Failed jobs for the health screen: counts and the latest few, never the payload or the trace. */
class FailedJobs
{
    public function report(int $limit = 20): array
    {
        $table = (string) config('queue.failed.table', 'failed_jobs');
        $limit = max(1, min($limit, 50));

        $total = DB::table($table)->count();
        $last24h = DB::table($table)->where('failed_at', '>=', now()->subDay())->count();
        $byQueue = DB::table($table)->select('queue', DB::raw('count(*) as total'))->groupBy('queue')->orderByDesc('total')->limit(20)->get();

        $items = DB::table($table)
            ->select(['id', 'uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at'])
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => $this->item($row))
            ->values();

        return [
            'total' => $total,
            'last24h' => $last24h,
            'byQueue' => $byQueue->map(fn ($r) => ['queue' => (string) $r->queue, 'total' => (int) $r->total])->values(),
            'items' => $items,
        ];
    }

    private function item(object $row): array
    {
        $payload = json_decode((string) $row->payload, true);
        $job = is_array($payload) ? ($payload['displayName'] ?? null) : null;
        $job = is_string($job) && preg_match('/^[\w\\\\@:.\- ]{1,150}$/', $job) ? $job : null;

        // First line of the stored exception is "Class: message in /path:line"; the rest is the trace.
        $first = trim((string) preg_split('/\R/', (string) $row->exception, 2)[0]);
        $class = null;
        $message = $first;
        if (preg_match('/^([A-Za-z_][\w\\\\]*): (.*)$/s', $first, $m)) {
            [$class, $message] = [$m[1], $m[2]];
        }

        return [
            'id' => (int) $row->id,
            'uuid' => $row->uuid,
            'job' => $job,
            'queue' => (string) $row->queue,
            'connection' => (string) $row->connection,
            'failedAt' => $row->failed_at ? Carbon::parse($row->failed_at)->toIso8601String() : null,
            'exceptionClass' => $class,
            'message' => Redactor::summary($message),
        ];
    }
}
