<?php

/*
 * Admin API: Events (A2). Included from routes/api/admin.php inside the staff group
 * (auth:sanctum + admin), so every route here is already prefixed `api/v1/admin`.
 * Each route declares its permission: ->middleware('admin.can:admin.<...>') (App\Support\AdminPermissions).
 * Every write goes through App\Http\Services\Admin\AdminAudit and asks for a reason when it is sensitive.
 */

use App\Http\Controllers\Admin\Events\EventController;
use Illuminate\Support\Facades\Route;

Route::prefix('events')->group(function () {
    Route::get('', [EventController::class, 'index'])->middleware('admin.can:admin.events.view');
    // Archived and soft-deleted events are still found by support.
    Route::get('{event}', [EventController::class, 'show'])->withTrashed()->whereNumber('event')->middleware('admin.can:admin.events.view');
});
