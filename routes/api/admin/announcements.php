<?php

/*
 * Admin API: Anuncios dentro de la app (A12a). Included from routes/api/admin.php inside the staff group. Read and
 * write both need admin.catalog.manage; writes need a recent re-authentication and a reason, and are audited.
 */

use App\Http\Controllers\Admin\Announcements\AnnouncementController;
use Illuminate\Support\Facades\Route;

Route::prefix('announcements')->middleware('admin.can:admin.catalog.manage')->group(function () {
    Route::get('', [AnnouncementController::class, 'index']);
    Route::post('audience-preview', [AnnouncementController::class, 'audiencePreview']);
    Route::post('', [AnnouncementController::class, 'store'])->middleware('admin.reauth');
    Route::put('{id}', [AnnouncementController::class, 'update'])->whereNumber('id')->middleware('admin.reauth');
    Route::delete('{id}', [AnnouncementController::class, 'destroy'])->whereNumber('id')->middleware('admin.reauth');
});
