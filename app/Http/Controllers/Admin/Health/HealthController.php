<?php

namespace App\Http\Controllers\Admin\Health;

use App\Http\Controllers\Controller;
use App\Http\Services\Admin\AdminAudit;
use App\Http\Services\Admin\Health\Connections;
use App\Http\Services\Admin\Health\FailedJobActions;
use App\Http\Services\Admin\Health\FailedJobs;
use App\Http\Services\Admin\Health\ModuleFlags;
use App\Http\Services\Admin\Health\QueueStatus;
use App\Http\Services\Admin\Health\RecentErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** System health (A10). Reads are `admin.system.health`; retry/delete of failed jobs is `admin.system.manage`. Each card of the screen has its own endpoint so one failing probe does not blank the rest. */
class HealthController extends Controller
{
    public function failedJobs(Request $request, FailedJobs $jobs): JsonResponse
    {
        $f = $request->validate(['limit' => ['nullable', 'integer', 'min:1', 'max:50']]);

        return response()->json($jobs->report($f['limit'] ?? 20));
    }

    public function queues(QueueStatus $queues): JsonResponse
    {
        return response()->json($queues->report() + ['worker' => [
            'status' => 'unknown',
            'note' => 'El worker no deja un latido que se pueda leer con fiabilidad; se infiere por la antigüedad del trabajo pendiente.',
        ], 'scheduler' => $queues->scheduler()]);
    }

    public function errors(Request $request, RecentErrors $errors): JsonResponse
    {
        $f = $request->validate(['limit' => ['nullable', 'integer', 'min:1', 'max:50']]);

        return response()->json($errors->report($f['limit'] ?? 20));
    }

    public function connections(Connections $connections): JsonResponse
    {
        return response()->json(['items' => $connections->report()]);
    }

    public function modules(ModuleFlags $flags): JsonResponse
    {
        return response()->json($flags->report());
    }

    public function retryJob(Request $request, string $uuid, FailedJobActions $actions, AdminAudit $audit): JsonResponse
    {
        $reason = $request->validate(['reason' => AdminAudit::reasonRule()])['reason'];
        $job = $actions->find($uuid) ?? abort(404, 'Failed job not found.');

        abort_unless($actions->retry($uuid), 422, 'Este trabajo no se puede reintentar: su contenido está dañado.');
        $audit->record('health.failed_job_retry', ['failed_job', $job['id']], ['reason' => $reason, 'after' => ['job' => $job['job'], 'queue' => $job['queue']]]);

        return response()->json(['retried' => 1]);
    }

    public function forgetJob(Request $request, string $uuid, FailedJobActions $actions, AdminAudit $audit): JsonResponse
    {
        $reason = $request->validate(['reason' => AdminAudit::reasonRule()])['reason'];
        $job = $actions->find($uuid) ?? abort(404, 'Failed job not found.');

        $actions->forget($uuid);
        $audit->record('health.failed_job_forget', ['failed_job', $job['id']], ['reason' => $reason, 'before' => ['job' => $job['job'], 'queue' => $job['queue']]]);

        return response()->json(['deleted' => 1]);
    }

    /** Retries up to FailedJobActions::BULK_LIMIT jobs of one queue (oldest first); call again for the rest. */
    public function retryQueue(Request $request, FailedJobActions $actions, AdminAudit $audit): JsonResponse
    {
        $f = $request->validate(['queue' => ['required', 'string', 'max:100'], 'reason' => AdminAudit::reasonRule()]);

        $batch = $actions->batch($f['queue']);
        $done = array_flip($actions->retryMany(array_column($batch, 'uuid')));
        $batch = array_values(array_filter($batch, fn ($j) => isset($done[$j['uuid']])));

        $audit->record('health.failed_jobs_retry_queue', ['failed_job', null], [
            'reason' => $f['reason'],
            'after' => [
                'queue' => $f['queue'],
                'count' => count($batch),
                'jobs' => collect($batch)->countBy(fn ($j) => $j['job'] ?? 'unknown')->all(),
            ],
        ]);

        return response()->json(['retried' => count($batch), 'remaining' => $actions->remaining($f['queue']), 'limit' => FailedJobActions::BULK_LIMIT]);
    }
}
