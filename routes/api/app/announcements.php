<?php

/*
 * App API: Anuncios dentro de la app (A12a). Included with one line at the end of routes/api/app.php; it carries its
 * own auth middleware, the same as the protected group there.
 */

use App\Http\Controllers\AppControllers\Announcements\AnnouncementController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'not.suspended', 'impersonation.guard', 'refresh.token'])->prefix('announcements')->name('announcements.')->group(function () {
    Route::get('', [AnnouncementController::class, 'index'])->name('index');
    Route::post('{announcement}/dismiss', [AnnouncementController::class, 'dismiss'])->whereNumber('announcement')->name('dismiss');
});
