<?php

/*
 * Admin API: System health (A10), read-only. Included from routes/api/admin.php inside the staff group
 * (auth:sanctum + admin), so every route here is already prefixed `api/v1/admin`.
 * The menu item declares `admin.catalog.manage` (superadmin only); there is no health-specific permission yet.
 */

use App\Http\Controllers\Admin\Health\HealthController;
use Illuminate\Support\Facades\Route;

Route::prefix('health')->middleware('admin.can:admin.catalog.manage')->group(function () {
    Route::get('failed-jobs', [HealthController::class, 'failedJobs']);
    Route::get('queues', [HealthController::class, 'queues']);
    Route::get('errors', [HealthController::class, 'errors']);
    Route::get('connections', [HealthController::class, 'connections']);
    Route::get('modules', [HealthController::class, 'modules']);
});
