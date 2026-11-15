<?php

/*
 * Admin API: Gestor de plantillas (A7), templates with immutable versions (docs/plans/admin-templates.md). Included
 * from routes/api/admin.php inside the staff group. Superadmin only (admin.templates.manage); writes are audited.
 */

use App\Http\Controllers\Admin\Templates\TemplateController;
use App\Http\Controllers\Admin\Templates\TemplatePackageController;
use Illuminate\Support\Facades\Route;

Route::prefix('templates')->middleware('admin.can:admin.templates.manage')->group(function () {
    // Installable packages (docs/plans/template-package-spec.md, 7.2). Literal paths: they never meet `{id}` (numeric).
    Route::prefix('packages')->group(function () {
        Route::get('', [TemplatePackageController::class, 'index']);
        Route::post('validate', [TemplatePackageController::class, 'validateUpload'])->middleware('throttle:admin-template-package-upload');
        Route::delete('stages/{token}', [TemplatePackageController::class, 'discardStage'])->where('token', '[A-Za-z0-9_-]{1,64}');
        Route::post('', [TemplatePackageController::class, 'install'])->middleware(['admin.reauth', 'throttle:admin-template-package-install']);
        Route::get('{id}', [TemplatePackageController::class, 'show'])->whereNumber('id');
        Route::get('{id}/impact', [TemplatePackageController::class, 'impact'])->whereNumber('id');
        Route::post('{id}/activate', [TemplatePackageController::class, 'activate'])->whereNumber('id')->middleware(['admin.reauth', 'throttle:admin-template-package-change']);
        Route::post('{id}/deactivate', [TemplatePackageController::class, 'deactivate'])->whereNumber('id')->middleware(['admin.reauth', 'throttle:admin-template-package-change']);
        Route::delete('{id}', [TemplatePackageController::class, 'destroy'])->whereNumber('id')->middleware(['admin.reauth', 'throttle:admin-template-package-change']);
    });

    Route::get('', [TemplateController::class, 'index']);
    Route::post('preview', [TemplateController::class, 'preview']);
    Route::get('versions/{version}', [TemplateController::class, 'showVersion'])->whereNumber('version');
    Route::patch('versions/{version}', [TemplateController::class, 'updateVersion'])->whereNumber('version');
    // Publishing and archiving a version change what organizers see: they need a recent re-authentication (D-3).
    Route::post('versions/{version}/publish', [TemplateController::class, 'publish'])->whereNumber('version')->middleware('admin.reauth');
    Route::post('versions/{version}/archive', [TemplateController::class, 'archive'])->whereNumber('version')->middleware('admin.reauth');
    Route::get('{id}', [TemplateController::class, 'show'])->whereNumber('id');
    Route::post('{id}/versions', [TemplateController::class, 'createVersion'])->whereNumber('id');
});
