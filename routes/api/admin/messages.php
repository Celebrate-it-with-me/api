<?php

/*
 * Admin API: editor of the transactional mail texts (docs/plans/message-editor-contract.md). Included from
 * routes/api/admin.php inside the staff group. Reads need admin.events.view; writes are superadmin only
 * (admin.messages.manage), re-authenticated and audited.
 */

use App\Http\Controllers\Admin\Messages\MessageController;
use Illuminate\Support\Facades\Route;

Route::prefix('messages')->group(function () {
    Route::middleware('admin.can:admin.events.view')->group(function () {
        Route::get('', [MessageController::class, 'index']);
        Route::get('{key}', [MessageController::class, 'show']);
        Route::post('{key}/preview', [MessageController::class, 'preview']);
    });

    Route::middleware(['admin.can:admin.messages.manage', 'admin.reauth'])->group(function () {
        Route::put('{key}', [MessageController::class, 'update']);
        Route::delete('{key}', [MessageController::class, 'destroy']);
        Route::post('{key}/test', [MessageController::class, 'test'])->middleware('throttle:5,60,message-test');
    });
});
