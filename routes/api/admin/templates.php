<?php

/*
 * Admin API: Gestor de plantillas (A7), templates with immutable versions (docs/plans/admin-templates.md). Included
 * from routes/api/admin.php inside the staff group. Superadmin only (admin.templates.manage); writes are audited.
 */

use App\Http\Controllers\Admin\Templates\TemplateController;
use Illuminate\Support\Facades\Route;

Route::prefix('templates')->middleware('admin.can:admin.templates.manage')->group(function () {
    Route::get('', [TemplateController::class, 'index']);
    Route::post('preview', [TemplateController::class, 'preview']);
    Route::get('versions/{version}', [TemplateController::class, 'showVersion'])->whereNumber('version');
    Route::patch('versions/{version}', [TemplateController::class, 'updateVersion'])->whereNumber('version');
    Route::post('versions/{version}/publish', [TemplateController::class, 'publish'])->whereNumber('version');
    Route::post('versions/{version}/archive', [TemplateController::class, 'archive'])->whereNumber('version');
    Route::get('{id}', [TemplateController::class, 'show'])->whereNumber('id');
    Route::post('{id}/versions', [TemplateController::class, 'createVersion'])->whereNumber('id');
});
