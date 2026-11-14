<?php

/*
 * Admin API: System health (A10) and system operations. Included from routes/api/admin.php inside the staff group
 * (auth:sanctum + admin), so every route here is already prefixed `api/v1/admin`.
 * Reads need `admin.system.health`; retrying/deleting failed jobs and maintenance mode need `admin.system.manage`;
 * the staff two-factor switch (`system/security`) needs `admin.security.manage`.
 */

use App\Http\Controllers\Admin\Health\HealthController;
use App\Http\Controllers\Admin\System\MaintenanceController;
use App\Http\Controllers\Admin\System\SecurityController;
use Illuminate\Support\Facades\Route;

Route::prefix('health')->group(function () {
    Route::middleware('admin.can:admin.system.health')->group(function () {
        Route::get('failed-jobs', [HealthController::class, 'failedJobs']);
        Route::get('queues', [HealthController::class, 'queues']);
        Route::get('errors', [HealthController::class, 'errors']);
        Route::get('connections', [HealthController::class, 'connections']);
        Route::get('modules', [HealthController::class, 'modules']);
    });

    Route::middleware('admin.can:admin.system.manage')->group(function () {
        Route::post('failed-jobs/retry', [HealthController::class, 'retryQueue']);
        Route::post('failed-jobs/{uuid}/retry', [HealthController::class, 'retryJob'])->whereUuid('uuid');
        Route::delete('failed-jobs/{uuid}', [HealthController::class, 'forgetJob'])->whereUuid('uuid');
    });
});

Route::prefix('system')->group(function () {
    Route::get('maintenance', [MaintenanceController::class, 'show'])->middleware('admin.can:admin.system.health');
    Route::put('maintenance', [MaintenanceController::class, 'update'])->middleware('admin.can:admin.system.manage');

    // "Require two-factor for all staff": reading and changing it are superadmin only (`admin.security.manage`); changing it needs a recent re-authentication.
    Route::get('security', [SecurityController::class, 'show'])->middleware('admin.can:admin.security.manage');
    Route::put('security', [SecurityController::class, 'update'])->middleware(['admin.can:admin.security.manage', 'admin.reauth']);
});
