<?php

namespace App\Http\Controllers\Admin\System;

use App\Http\Controllers\Controller;
use App\Http\Services\Admin\AdminAudit;
use App\Http\Services\Admin\System\MaintenanceMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class MaintenanceController extends Controller
{
    public function show(MaintenanceMode $maintenance): JsonResponse
    {
        return response()->json($maintenance->state() + ['maxHours' => MaintenanceMode::MAX_HOURS]);
    }

    public function update(Request $request, MaintenanceMode $maintenance, AdminAudit $audit): JsonResponse
    {
        $f = $request->validate([
            'active' => ['required', 'boolean'],
            'message' => ['nullable', 'string', 'max:300'],
            'estimatedEnd' => ['nullable', 'date', 'after:now', 'before:' . now()->addHours(MaintenanceMode::MAX_HOURS)->toIso8601String()],
            'reason' => AdminAudit::reasonRule(),
        ]);

        $before = $maintenance->state();
        $state = $f['active']
            ? $maintenance->enable($f['message'] ?? null, isset($f['estimatedEnd']) ? Carbon::parse($f['estimatedEnd']) : null, $request->user())
            : $maintenance->disable($request->user());

        $audit->record($f['active'] ? 'system.maintenance_on' : 'system.maintenance_off', ['system', null], [
            'reason' => $f['reason'],
            'before' => ['active' => $before['active']],
            'after' => ['active' => $state['active'], 'message' => $state['message'], 'estimatedEnd' => $state['estimatedEnd']],
        ]);

        return response()->json($state + ['maxHours' => MaintenanceMode::MAX_HOURS]);
    }
}
