<?php

/*
 * Admin API: Moderation (A6). Included from routes/api/admin.php inside the staff group
 * (auth:sanctum + admin), so every route here is already prefixed `api/v1/admin`.
 * Each route declares its permission: ->middleware('admin.can:admin.<...>') (App\Support\AdminPermissions).
 * Every write goes through App\Http\Services\Admin\AdminAudit and asks for a reason when it is sensitive.
 */

use App\Http\Controllers\Admin\Moderation\ModerationController;
use Illuminate\Support\Facades\Route;

Route::prefix('moderation')->middleware('admin.can:admin.content.moderate')->group(function () {
    Route::get('', [ModerationController::class, 'index']);
    Route::post('bulk', [ModerationController::class, 'bulk']);
    Route::post('{type}/{id}/hide', [ModerationController::class, 'hide'])->whereNumber('id');
    Route::post('{type}/{id}/restore', [ModerationController::class, 'restore'])->whereNumber('id');
    Route::delete('{type}/{id}', [ModerationController::class, 'destroy'])->whereNumber('id');
});
