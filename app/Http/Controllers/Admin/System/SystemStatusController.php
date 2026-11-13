<?php

namespace App\Http\Controllers\Admin\System;

use App\Http\Controllers\Controller;
use App\Http\Services\Admin\System\MaintenanceMode;
use Illuminate\Http\JsonResponse;

/** Public and unauthenticated: only whether we are in maintenance, the message and the estimated end. Nothing internal. */
class SystemStatusController extends Controller
{
    public function __invoke(MaintenanceMode $maintenance): JsonResponse
    {
        $s = $maintenance->state();

        return response()->json([
            'maintenance' => $s['active'],
            'message' => $s['message'],
            'estimatedEnd' => $s['estimatedEnd'],
        ])->header('Cache-Control', 'no-store');
    }
}
