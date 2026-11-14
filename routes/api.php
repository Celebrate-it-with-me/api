<?php
/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group.
|
| Please note:
| - Admin-specific routes are defined in the "routes/admin.php" file
|   and are assigned to the "admin" prefix and middleware group.
| - App-specific routes are defined in the "routes/app.php" file and
|   are assigned to the "app" prefix and middleware group.
|
| Make something great!
|
*/


// Public, unauthenticated: just enough for the SPA to show a "we are under maintenance" notice.
Route::get('public/system-status', \App\Http\Controllers\Admin\System\SystemStatusController::class)->middleware('throttle:60,1');
