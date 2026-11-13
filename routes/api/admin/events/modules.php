<?php

/*
 * Admin API: Per-event module overrides (Funciones tab). Included from routes/api/admin/events.php (inside the staff group, prefix api/v1/admin).
 * Paths start with `events/{event}/...`. Each route declares its permission and every write is audited.
 */

use App\Http\Controllers\Admin\Events\ModuleOverrideController;
use Illuminate\Support\Facades\Route;

Route::prefix('events/{event}/modules')->whereNumber('event')->group(function () {
    Route::get('', [ModuleOverrideController::class, 'index'])->withTrashed()->middleware('admin.can:admin.events.view');
    Route::put('{module}', [ModuleOverrideController::class, 'update'])->middleware('admin.can:admin.modules.gift');
    Route::delete('{module}', [ModuleOverrideController::class, 'destroy'])->middleware('admin.can:admin.modules.gift');
});
