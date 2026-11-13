<?php

/*
 * Admin API: Users (A5). Included from routes/api/admin.php inside the staff group
 * (auth:sanctum + admin), so every route here is already prefixed `api/v1/admin`.
 * Each route declares its permission: ->middleware('admin.can:admin.<...>') (App\Support\AdminPermissions).
 * Every write goes through App\Http\Services\Admin\AdminAudit and asks for a reason when it is sensitive.
 */

use App\Http\Controllers\Admin\Users\UserAdminController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('users', [UserAdminController::class, 'index'])->middleware('admin.can:admin.users.view');
Route::get('users/{user}', [UserAdminController::class, 'show'])->middleware('admin.can:admin.users.view');
Route::post('users/{user}/password-reset', [UserAdminController::class, 'passwordReset'])->middleware('admin.can:admin.users.reset_password');
Route::post('users/{user}/suspend', [UserAdminController::class, 'suspend'])->middleware('admin.can:admin.users.suspend');
Route::post('users/{user}/unsuspend', [UserAdminController::class, 'unsuspend'])->middleware('admin.can:admin.users.suspend');
Route::get('users/{user}/export', [UserAdminController::class, 'export'])->middleware('admin.can:admin.users.export');
Route::delete('users/{user}', [UserAdminController::class, 'destroy'])->middleware('admin.can:admin.users.delete');

// Legacy create/edit, still used by the old screen. Audited.
Route::post('users', [UserController::class, 'store'])->middleware('admin.can:admin.users.manage');
Route::match(['put', 'patch'], 'users/{user}', [UserController::class, 'update'])->middleware('admin.can:admin.users.manage');
