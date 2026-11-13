<?php

/*
 * Admin API: Impersonation (A3). Included from routes/api/admin.php inside the staff group
 * (auth:sanctum + admin), so every route here is already prefixed `api/v1/admin`.
 * Each route declares its permission: ->middleware('admin.can:admin.<...>') (App\Support\AdminPermissions).
 * Every write goes through App\Http\Services\Admin\AdminAudit and asks for a reason when it is sensitive.
 */

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Admin\Impersonation\ImpersonationController;

Route::post('users/{user}/impersonate', [ImpersonationController::class, 'store'])
    ->middleware('admin.can:admin.impersonate');
