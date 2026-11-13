<?php

/*
 * Admin API: Users (A5). Included from routes/api/admin.php inside the staff group
 * (auth:sanctum + admin), so every route here is already prefixed `api/v1/admin`.
 * Each route declares its permission: ->middleware('admin.can:admin.<...>') (App\Support\AdminPermissions).
 * Every write goes through App\Http\Services\Admin\AdminAudit and asks for a reason when it is sensitive.
 */

use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('users', [UserController::class, 'index'])->middleware('admin.can:admin.users.view');
Route::get('users/{user}', [UserController::class, 'show'])->middleware('admin.can:admin.users.view');
Route::post('users', [UserController::class, 'store'])->middleware('admin.can:admin.users.manage');
Route::match(['put', 'patch'], 'users/{user}', [UserController::class, 'update'])->middleware('admin.can:admin.users.manage');
Route::delete('users/{user}', [UserController::class, 'destroy'])->middleware('admin.can:admin.users.delete');
