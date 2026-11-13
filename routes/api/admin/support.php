<?php

/*
 * Admin API: Support inbox (A13). Included from routes/api/admin.php inside the staff group
 * (auth:sanctum + admin), so every route here is already prefixed `api/v1/admin`.
 * Each route declares its permission: ->middleware('admin.can:admin.<...>') (App\Support\AdminPermissions).
 * Every write goes through App\Http\Services\Admin\AdminAudit and asks for a reason when it is sensitive.
 */

use App\Http\Controllers\Admin\Support\SupportController;
use Illuminate\Support\Facades\Route;

Route::prefix('support')->middleware('admin.can:admin.support.manage')->group(function () {
    Route::get('', [SupportController::class, 'index']);
    Route::get('{supportRequest}', [SupportController::class, 'show'])->whereNumber('supportRequest');
    Route::patch('{supportRequest}', [SupportController::class, 'update'])->whereNumber('supportRequest');
    Route::post('{supportRequest}/notes', [SupportController::class, 'note'])->whereNumber('supportRequest');
});
