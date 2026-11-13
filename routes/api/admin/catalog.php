<?php

/*
 * Admin API: Módulos y planes (A4), the catalog of modules and which plans include each one. Included from
 * routes/api/admin.php inside the staff group. Support reads (admin.catalog.view); only superadmin writes
 * (admin.catalog.manage). Every write is audited.
 */

use App\Http\Controllers\Admin\Catalog\CatalogController;
use Illuminate\Support\Facades\Route;

Route::prefix('catalog')->group(function () {
    Route::get('', [CatalogController::class, 'index'])->middleware('admin.can:admin.catalog.view');
    Route::get('impact', [CatalogController::class, 'impact'])->middleware('admin.can:admin.catalog.view');
    Route::get('plans/{slug}/impact', [CatalogController::class, 'planImpact'])->middleware('admin.can:admin.catalog.view');
    Route::patch('plans/{slug}', [CatalogController::class, 'updatePlan'])->middleware('admin.can:admin.catalog.manage');
    Route::put('modules/{module}/plans', [CatalogController::class, 'updatePlans'])->middleware('admin.can:admin.catalog.manage');
    Route::patch('modules/{module}', [CatalogController::class, 'toggle'])->middleware('admin.can:admin.catalog.manage');
});
