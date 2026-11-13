<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Services\Admin\DashboardMetrics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardMetrics $metrics): JsonResponse
    {
        $data = $request->validate(['days' => ['nullable', 'integer', 'in:7,30,90'], 'internal' => ['nullable', 'boolean']]);

        return response()->json(['data' => $metrics->build((int) ($data['days'] ?? 30), (bool) ($data['internal'] ?? false), $request->user()->can('admin.purchases.manage'))]);
    }
}
