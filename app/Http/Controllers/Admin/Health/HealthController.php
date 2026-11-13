<?php

namespace App\Http\Controllers\Admin\Health;

use App\Http\Controllers\Controller;
use App\Http\Services\Admin\Health\Connections;
use App\Http\Services\Admin\Health\FailedJobs;
use App\Http\Services\Admin\Health\ModuleFlags;
use App\Http\Services\Admin\Health\QueueStatus;
use App\Http\Services\Admin\Health\RecentErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** System health (A10), read-only. Each card of the screen has its own endpoint so one failing probe does not blank the rest. */
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
}
