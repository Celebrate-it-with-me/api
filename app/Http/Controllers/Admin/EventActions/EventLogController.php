<?php

namespace App\Http\Controllers\Admin\EventActions;

use App\Http\Controllers\Controller;
use App\Http\Services\Admin\EventActions\EventLogService;
use App\Models\Events;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EventLogController extends Controller
{
    public function __construct(private readonly EventLogService $log) {}

    /** `source=activity` (default) is the event's own history; `source=staff` is the panel's audit log and needs admin.audit.view. */
    public function __invoke(Request $request, Events $event): JsonResponse
    {
        $f = $request->validate([
            'source' => ['nullable', Rule::in(['activity', 'staff'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:5', 'max:50'],
        ]);

        $canStaff = $request->user()->hasPermissionTo('admin.audit.view', 'web');
        $source = $f['source'] ?? 'activity';
        abort_if($source === 'staff' && ! $canStaff, 403, 'Your role cannot do this.');

        $res = $source === 'staff'
            ? $this->log->staff($event, $f['page'] ?? 1, $f['perPage'] ?? 20)
            : $this->log->activity($event, $f['page'] ?? 1, $f['perPage'] ?? 20);
        $res['meta'] += ['source' => $source, 'canViewStaff' => $canStaff];

        return response()->json($res);
    }
}
